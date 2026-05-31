<?php

use Anilcancakir\LaravelAgentMcp\Commands\ToolSchemaCommand;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

// agent-mcp:schema prints a tool's input schema without invoking it. Local mode renders the
// tool's own inputSchema; remote mode pulls it from the server tools/list. An unknown tool
// exits non-zero.

/**
 * @param  array<string, mixed>  $args
 * @return array{stdout: string, stderr: string, status: int}
 */
function runSchema(array $args): array
{
    $stdout = fopen('php://memory', 'r+');
    $stderr = fopen('php://memory', 'r+');

    $command = new ToolSchemaCommand;
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

it('prints a local tool input schema', function (): void {
    $result = runSchema(['tool' => 'db_schema']);

    expect($result['status'])->toBe(0);

    $schema = json_decode($result['stdout'], true);

    expect($schema['name'])->toBe('db_schema');
    expect($schema)->toHaveKey('inputSchema');
    // db_schema accepts an optional "table" argument.
    expect($result['stdout'])->toContain('table');
});

it('exits non-zero on an unknown tool', function (): void {
    $result = runSchema(['tool' => 'does_not_exist']);

    expect($result['status'])->toBe(1);
    expect($result['stderr'])->toContain('Unknown tool');
});

it('pulls the schema from the remote tools/list in remote mode', function (): void {
    putenv('AGENT_MCP_URL=https://remote.test/agent-mcp');
    putenv('AGENT_MCP_KEY=super-secret-key-value');

    Http::fake([
        'remote.test/*' => Http::response(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'tools' => [
                    [
                        'name' => 'db_schema',
                        'description' => 'Schema',
                        'inputSchema' => ['type' => 'object', 'properties' => ['table' => ['type' => 'string']]],
                    ],
                ],
            ],
        ]), 200),
    ]);

    $result = runSchema(['tool' => 'db_schema', '--remote' => true]);

    expect($result['status'])->toBe(0);
    expect($result['stdout'])->toContain('table');
    expect($result['stdout'])->not->toContain('super-secret-key-value');

    putenv('AGENT_MCP_URL');
    putenv('AGENT_MCP_KEY');
});
