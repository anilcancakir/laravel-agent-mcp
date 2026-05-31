<?php

use Anilcancakir\LaravelAgentMcp\Commands\CallToolCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

// agent-mcp:call invokes a single tool from the shell. Local mode runs the tool in-process
// (inheriting its authorize/audit/redaction gate); remote mode forwards a stateless
// tools/call to AGENT_MCP_URL. stdout carries the payload, stderr the diagnostics, and the
// exit code follows the tool's isError flag. The server key never reaches stdout/stderr.

beforeEach(function (): void {
    config()->set('agent-mcp.audit.enabled', false);
});

/**
 * Run agent-mcp:call with the given input arguments/options + an optional STDIN body, and
 * capture stdout, stderr, and the exit code separately.
 *
 * @param  array<string, mixed>  $args
 * @return array{stdout: string, stderr: string, status: int}
 */
function runCall(array $args, ?string $stdin = null): array
{
    $stdout = fopen('php://memory', 'r+');
    $stderr = fopen('php://memory', 'r+');

    $command = new CallToolCommand;
    $command->usingOutputStream($stdout);
    $command->usingErrorStream($stderr);

    if ($stdin !== null) {
        $in = fopen('php://memory', 'r+');
        fwrite($in, $stdin);
        rewind($in);
        $command->usingInputStream($in);
    }

    $command->setLaravel(app());

    $status = $command->run(new ArrayInput($args), new NullOutput);

    rewind($stdout);
    rewind($stderr);

    return [
        'stdout' => (string) stream_get_contents($stdout),
        'stderr' => (string) stream_get_contents($stderr),
        'status' => $status,
    ];
}

it('invokes an enabled tool locally and prints its payload with exit 0', function (): void {
    $result = runCall(['tool' => 'app_about', 'input' => '{}']);

    expect($result['status'])->toBe(0);
    expect($result['stdout'])->toContain('environment');
});

it('reads JSON arguments from STDIN when the input argument is omitted', function (): void {
    $result = runCall(['tool' => 'app_about'], '{}');

    expect($result['status'])->toBe(0);
    expect($result['stdout'])->toContain('environment');
});

it('exits non-zero with a denial when the tool is disabled in config', function (): void {
    config()->set('agent-mcp.tools.config_inspect', false);

    $result = runCall(['tool' => 'config_inspect', 'input' => '{"key":"app"}']);

    expect($result['status'])->toBe(1);
    expect($result['stdout'])->toContain('disabled');
});

it('exits non-zero on malformed JSON arguments', function (): void {
    $result = runCall(['tool' => 'app_about', 'input' => '{not valid']);

    expect($result['status'])->toBe(1);
    expect($result['stderr'])->toContain('Invalid JSON');
});

it('exits non-zero on an unknown tool', function (): void {
    $result = runCall(['tool' => 'does_not_exist', 'input' => '{}']);

    expect($result['status'])->toBe(1);
    expect($result['stderr'])->toContain('Unknown tool');
});

it('forwards to the remote endpoint with a Bearer key and prints the result, never leaking the key', function (): void {
    putenv('AGENT_MCP_URL=https://remote.test/agent-mcp');
    putenv('AGENT_MCP_KEY=super-secret-key-value');

    Http::fake([
        'remote.test/*' => Http::response(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'content' => [['type' => 'text', 'text' => '{"tables":["users"]}']],
                'isError' => false,
            ],
        ]), 200),
    ]);

    $result = runCall(['tool' => 'db_schema', 'input' => '{"table":"users"}', '--remote' => true]);

    expect($result['status'])->toBe(0);
    expect($result['stdout'])->toContain('"tables"');
    expect($result['stdout'])->not->toContain('super-secret-key-value');
    expect($result['stderr'])->not->toContain('super-secret-key-value');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer super-secret-key-value')
        && json_decode($request->body(), true)['method'] === 'tools/call');

    putenv('AGENT_MCP_URL');
    putenv('AGENT_MCP_KEY');
});
