<?php

use Anilcancakir\LaravelAgentMcp\Commands\ListToolsCommand;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

// agent-mcp:tools lists the available tools. Local mode reports the roster filtered by the
// per-tool enable flag (--all includes disabled, flagged enabled=false); remote mode returns
// the server's already-enabled list. Output is a JSON array.

beforeEach(function (): void {
    config()->set('agent-mcp.tools.db_schema', true);
    config()->set('agent-mcp.tools.config_inspect', false);
});

/**
 * @param  array<string, mixed>  $args
 * @return array{stdout: string, stderr: string, status: int}
 */
function runTools(array $args): array
{
    $stdout = fopen('php://memory', 'r+');
    $stderr = fopen('php://memory', 'r+');

    $command = new ListToolsCommand;
    $command->usingOutputStream($stdout);
    $command->usingErrorStream($stderr);
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

it('lists enabled tools and omits disabled ones by default', function (): void {
    $result = runTools([]);

    expect($result['status'])->toBe(0);

    $tools = json_decode($result['stdout'], true);
    $names = array_column($tools, 'name');

    expect($names)->toContain('db_schema');
    expect($names)->not->toContain('config_inspect');
});

it('includes disabled tools flagged enabled=false with --all', function (): void {
    $result = runTools(['--all' => true]);

    $byName = [];

    foreach (json_decode($result['stdout'], true) as $tool) {
        $byName[$tool['name']] = $tool;
    }

    expect($byName)->toHaveKey('config_inspect');
    expect($byName['config_inspect']['enabled'])->toBeFalse();
    expect($byName['db_schema']['enabled'])->toBeTrue();
});

it('lists the remote tool set in remote mode', function (): void {
    putenv('AGENT_MCP_URL=https://remote.test/agent-mcp');
    putenv('AGENT_MCP_KEY=super-secret-key-value');

    Http::fake([
        'remote.test/*' => Http::response(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'tools' => [
                    ['name' => 'db_schema', 'description' => 'Schema'],
                    ['name' => 'app_about', 'description' => 'About'],
                ],
            ],
        ]), 200),
    ]);

    $result = runTools(['--remote' => true]);

    expect($result['status'])->toBe(0);
    expect(array_column(json_decode($result['stdout'], true), 'name'))->toBe(['db_schema', 'app_about']);
    expect($result['stdout'])->not->toContain('super-secret-key-value');

    putenv('AGENT_MCP_URL');
    putenv('AGENT_MCP_KEY');
});
