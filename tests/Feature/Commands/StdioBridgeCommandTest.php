<?php

use Anilcancakir\LaravelAgentMcp\Commands\StdioBridgeCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use function Pest\Laravel\withHeaders;

// agent-mcp:stdio bridges a local stdio MCP client to a REMOTE HTTP MCP endpoint.
// Connection details come from operator-set ENV (the client's .mcp.json `env`):
//   AGENT_MCP_URL (required) + AGENT_MCP_KEY (required).
// Each newline-delimited JSON-RPC line on STDIN is POSTed raw to the remote with a
// Bearer key; the remote reply is written to STDOUT as one \n-terminated line.
// Security invariants under test:
//   - the key only ever travels in the Authorization header, never on stdout/stderr;
//   - TLS verification is never disabled;
//   - missing ENV fails fast to STDERR with no stdout noise.

/**
 * Drive a set of JSON-RPC lines through the command's STDIN loop with a fake stream
 * and capture the emitted STDOUT plus the process exit code.
 *
 * @param  array<int, string>  $lines
 * @return array{stdout: string, status: int}
 */
function runBridge(array $lines): array
{
    $stream = fopen('php://memory', 'r+');
    $stdout = fopen('php://memory', 'r+');
    $stderr = fopen('php://memory', 'r+');

    foreach ($lines as $line) {
        fwrite($stream, $line."\n");
    }

    rewind($stream);

    $command = new StdioBridgeCommand;
    $command->usingInputStream($stream);
    $command->usingOutputStream($stdout);
    $command->usingErrorStream($stderr);
    $command->setLaravel(app());

    $status = $command->run(
        new ArrayInput([]),
        new NullOutput,
    );

    rewind($stdout);
    $emitted = stream_get_contents($stdout);

    fclose($stream);
    fclose($stdout);
    fclose($stderr);

    return [
        'stdout' => $emitted,
        'status' => $status,
    ];
}

/**
 * Drive lines through the loop while capturing STDOUT and STDERR separately.
 *
 * @param  array<int, string>  $lines
 * @return array{0: int, 1: string, 2: string}
 */
function runBridgeWithStreams(array $lines): array
{
    $stream = fopen('php://memory', 'r+');
    $stdout = fopen('php://memory', 'r+');
    $stderr = fopen('php://memory', 'r+');

    foreach ($lines as $line) {
        fwrite($stream, $line."\n");
    }

    rewind($stream);

    $command = new StdioBridgeCommand;
    $command->usingInputStream($stream);
    $command->usingOutputStream($stdout);
    $command->usingErrorStream($stderr);
    $command->setLaravel(app());

    $status = $command->run(
        new ArrayInput([]),
        new NullOutput,
    );

    rewind($stdout);
    rewind($stderr);

    $emittedOut = stream_get_contents($stdout);
    $emittedErr = stream_get_contents($stderr);

    fclose($stream);
    fclose($stdout);
    fclose($stderr);

    return [$status, $emittedOut, $emittedErr];
}

beforeEach(function (): void {
    putenv('AGENT_MCP_URL=https://remote.test/agent-mcp');
    putenv('AGENT_MCP_KEY=super-secret-key-value');
});

afterEach(function (): void {
    putenv('AGENT_MCP_URL');
    putenv('AGENT_MCP_KEY');
});

it('forwards a JSON-RPC line to the remote with the Bearer key and writes the reply to stdout', function (): void {
    Http::fake([
        'remote.test/*' => Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200),
    ]);

    $result = runBridge(['{"jsonrpc":"2.0","id":1,"method":"ping"}']);

    expect($result['status'])->toBe(0);
    expect($result['stdout'])->toBe('{"jsonrpc":"2.0","id":1,"result":{}}'."\n");

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://remote.test/agent-mcp'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer super-secret-key-value')
            && $request->hasHeader('Accept', 'application/json, text/event-stream')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('MCP-Protocol-Version')
            && $request->body() === '{"jsonrpc":"2.0","id":1,"method":"ping"}';
    });
});

it('mirrors the method and tool name into headers so laravel/mcp 1.x accepts the forwarded call', function (): void {
    Http::fake([
        'remote.test/*' => Http::response('{"jsonrpc":"2.0","id":7,"result":{}}', 200),
    ]);

    runBridge(['{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"db_schema","arguments":{}}}']);

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('Mcp-Method', 'tools/call')
            && $request->hasHeader('Mcp-Name', 'db_schema')
            && $request->hasHeader('MCP-Protocol-Version', '2025-06-18');
    });
});

it('forwards the protocol version a client negotiates in _meta instead of the bridge default', function (): void {
    Http::fake([
        'remote.test/*' => Http::response('{"jsonrpc":"2.0","id":8,"result":{}}', 200),
    ]);

    $line = json_encode([
        'jsonrpc' => '2.0',
        'id' => 8,
        'method' => 'tools/list',
        'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
    ]);

    runBridge([$line]);

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('MCP-Protocol-Version', '2026-07-28')
            && $request->hasHeader('Mcp-Method', 'tools/list')
            && ! $request->hasHeader('Mcp-Name');
    });
});

it('sends headers the real MCP route accepts for a non-legacy tools/call', function (): void {
    config()->set('agent-mcp.key', 'super-secret-key-value');

    $forwarded = null;

    Http::fake(function (Request $request) use (&$forwarded) {
        $forwarded = $request;

        return Http::response('{"jsonrpc":"2.0","id":11,"result":{}}', 200);
    });

    $line = json_encode([
        'jsonrpc' => '2.0',
        'id' => 11,
        'method' => 'tools/call',
        'params' => [
            'name' => 'app_info',
            'arguments' => ['section' => 'env_keys'],
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new stdClass,
            ],
        ],
    ]);

    runBridge([$line]);

    assert($forwarded instanceof Request);

    // Replay exactly what the bridge sent against the package's own route: on laravel/mcp 1.x
    // a header that disagrees with the body is a 400 with error -32020 before any tool runs.
    $headers = array_map(static fn (array $values): string => $values[0], $forwarded->headers());

    withHeaders($headers)
        ->postJson('/agent-mcp', json_decode($forwarded->body(), true))
        ->assertOk()
        ->assertJsonMissingPath('error');
});

it('drops a mirrored header whose value is not header safe instead of copying it from stdin', function (): void {
    Http::fake([
        'remote.test/*' => Http::response('{"jsonrpc":"2.0","id":9,"result":{}}', 200),
    ]);

    $line = json_encode([
        'jsonrpc' => '2.0',
        'id' => 9,
        'method' => 'tools/call',
        'params' => ['name' => "db_schema\r\nX-Injected: 1", 'arguments' => []],
    ]);

    $result = runBridge([$line]);

    expect($result['status'])->toBe(0);

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('Mcp-Method', 'tools/call')
            && ! $request->hasHeader('Mcp-Name')
            && ! $request->hasHeader('X-Injected');
    });
});

it('never disables TLS verification on the forwarded request', function (): void {
    $verifyOption = null;
    $forwardedUrl = null;

    Http::fake(function (Request $request, array $options) use (&$verifyOption, &$forwardedUrl) {
        $verifyOption = $options['verify'] ?? 'unset';
        $forwardedUrl = $request->url();

        return Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200);
    });

    runBridge(['{"jsonrpc":"2.0","id":1,"method":"ping"}']);

    // Guzzle defaults verify to true; the only way it is false is an explicit
    // withoutVerifying() call, which the command must never make.
    expect($verifyOption)->not->toBe(false);
    expect($forwardedUrl)->toBe('https://remote.test/agent-mcp');
});

it('never leaks the key or Authorization header to stdout', function (): void {
    Http::fake([
        'remote.test/*' => Http::response('{"jsonrpc":"2.0","id":1,"result":{"ok":true}}', 200),
    ]);

    $result = runBridge(['{"jsonrpc":"2.0","id":1,"method":"ping"}']);

    expect($result['stdout'])->not->toContain('super-secret-key-value');
    expect($result['stdout'])->not->toContain('Bearer');
    expect($result['stdout'])->not->toContain('Authorization');
});

it('echoes Mcp-Session-Id from the initialize response on subsequent requests', function (): void {
    Http::fakeSequence('remote.test/*')
        ->push('{"jsonrpc":"2.0","id":1,"result":{}}', 200, ['Mcp-Session-Id' => 'sess-abc-123'])
        ->push('{"jsonrpc":"2.0","id":2,"result":{}}', 200);

    runBridge([
        '{"jsonrpc":"2.0","id":1,"method":"initialize"}',
        '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
    ]);

    Http::assertSentInOrder([
        function (Request $request): bool {
            return $request->body() === '{"jsonrpc":"2.0","id":1,"method":"initialize"}'
                && ! $request->hasHeader('Mcp-Session-Id');
        },
        function (Request $request): bool {
            return $request->body() === '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
                && $request->hasHeader('Mcp-Session-Id', 'sess-abc-123');
        },
    ]);
});

it('writes a JSON-RPC error to stdout and a scrubbed diagnostic to stderr on a non-2xx remote response', function (): void {
    Http::fake([
        'remote.test/*' => Http::response('upstream auth failed', 401),
    ]);

    $result = runBridge(['{"jsonrpc":"2.0","id":7,"method":"ping"}']);

    // STDOUT carries a generic JSON-RPC error object matching the request id, and
    // it must never carry the key, the Bearer token, or the Authorization header.
    $emitted = json_decode(trim($result['stdout']), true);

    expect($emitted)->toBeArray();
    expect($emitted['jsonrpc'])->toBe('2.0');
    expect($emitted['id'])->toBe(7);
    expect($emitted)->toHaveKey('error');
    expect($result['stdout'])->not->toContain('super-secret-key-value');
    expect($result['stdout'])->not->toContain('Bearer');
});

it('never leaks the key into the stderr diagnostic on the error path', function (): void {
    Http::fake([
        'remote.test/*' => Http::response('upstream auth failed', 401),
    ]);

    // Capture STDERR by swapping the command's error stream for a memory buffer.
    $stderr = fopen('php://memory', 'r+');
    $stdout = fopen('php://memory', 'r+');

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, '{"jsonrpc":"2.0","id":7,"method":"ping"}'."\n");
    rewind($stream);

    $command = new StdioBridgeCommand;
    $command->usingInputStream($stream);
    $command->usingOutputStream($stdout);
    $command->usingErrorStream($stderr);
    $command->setLaravel(app());

    $command->run(
        new ArrayInput([]),
        new NullOutput,
    );

    rewind($stderr);
    $errorOutput = stream_get_contents($stderr);

    fclose($stream);
    fclose($stdout);
    fclose($stderr);

    expect($errorOutput)->not->toContain('super-secret-key-value');
    expect($errorOutput)->not->toContain('Bearer super-secret-key-value');
});

it('fails fast to stderr with a non-zero exit and no stdout when AGENT_MCP_URL is missing', function (): void {
    putenv('AGENT_MCP_URL');

    [$status, $stdout, $errorOutput] = runBridgeWithStreams(['{"jsonrpc":"2.0","id":1,"method":"ping"}']);

    expect($status)->not->toBe(0);
    expect($stdout)->toBe('');
    expect($errorOutput)->toContain('AGENT_MCP_URL');
});

it('fails fast to stderr with a non-zero exit and no stdout when AGENT_MCP_KEY is missing', function (): void {
    putenv('AGENT_MCP_KEY');

    [$status, $stdout, $errorOutput] = runBridgeWithStreams(['{"jsonrpc":"2.0","id":1,"method":"ping"}']);

    expect($status)->not->toBe(0);
    expect($stdout)->toBe('');
    expect($errorOutput)->toContain('AGENT_MCP_KEY');
});

it('is registered as an artisan command by the package provider', function (): void {
    // The provider wires the command via Package::hasCommand so an operator can run
    // `php artisan agent-mcp:stdio`; without registration the bridge is unreachable.
    expect(Artisan::all())->toHaveKey('agent-mcp:stdio');
});
