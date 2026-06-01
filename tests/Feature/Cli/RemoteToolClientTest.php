<?php

use Anilcancakir\LaravelAgentMcp\Cli\RemoteInvocationException;
use Anilcancakir\LaravelAgentMcp\Cli\RemoteToolClient;
use Anilcancakir\LaravelAgentMcp\Support\InstallMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

// RemoteToolClient is the remote-mode HTTP surface the CLI composes. It POSTs a single
// stateless JSON-RPC tools/call (no initialize handshake), follows tools/list pagination
// via nextCursor, and mirrors StdioBridgeCommand's scrub discipline: the key only ever
// travels in the Authorization header, never in a thrown message or any echoed output.
// The remote URL is single-sourced: AGENT_MCP_URL env wins, else the committed url in
// .agent-mcp.json (InstallMode::committedUrl(), read raw so a bad scheme errors loudly).

beforeEach(function (): void {
    putenv('AGENT_MCP_URL=https://remote.test/agent-mcp');
    putenv('AGENT_MCP_KEY=super-secret-key-value');
    File::delete(InstallMode::path());
});

afterEach(function (): void {
    putenv('AGENT_MCP_URL');
    putenv('AGENT_MCP_KEY');
    File::delete(InstallMode::path());
});

it('reports configured true when a remote url is present (env or committed file)', function (): void {
    // 1. Env url present -> configured.
    expect((new RemoteToolClient)->configured())->toBeTrue();

    // 2. No env url, no committed url -> not configured (no remote intent).
    putenv('AGENT_MCP_URL');
    expect((new RemoteToolClient)->configured())->toBeFalse();

    // 3. A committed url alone signals remote intent, even without an env url.
    InstallMode::write('cli', 'https://committed.test/agent-mcp');
    expect((new RemoteToolClient)->configured())->toBeTrue();
});

it('lets the env url win over a committed file url', function (): void {
    putenv('AGENT_MCP_URL=https://env-wins.test/agent-mcp');
    InstallMode::write('cli', 'https://committed.test/agent-mcp');

    Http::fake([
        'env-wins.test/*' => Http::response(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['content' => [], 'isError' => false],
        ]), 200),
    ]);

    (new RemoteToolClient)->callTool('app_about', []);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://env-wins.test/agent-mcp');
});

it('uses the committed file url when the env url is unset', function (): void {
    putenv('AGENT_MCP_URL');
    InstallMode::write('cli', 'https://committed.test/agent-mcp');

    Http::fake([
        'committed.test/*' => Http::response(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['content' => [], 'isError' => false],
        ]), 200),
    ]);

    (new RemoteToolClient)->callTool('app_about', []);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://committed.test/agent-mcp'
            && $request->hasHeader('Authorization', 'Bearer super-secret-key-value');
    });
});

it('throws and sends nothing when an env url has a bad (plaintext) scheme', function (): void {
    // The env override is read raw, so a bad-scheme env url reaches the RemoteUrl
    // guard in post() and must error loudly before any request leaves the box.
    putenv('AGENT_MCP_URL=http://remote.test/agent-mcp');

    Http::fake();

    $caught = null;

    try {
        (new RemoteToolClient)->callTool('db_schema', []);
    } catch (RemoteInvocationException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull();
    expect($caught->getMessage())->not->toContain('remote.test');
    expect($caught->getMessage())->not->toContain('http://');
    Http::assertNothingSent();
});

it('surfaces a hand-edited bad-scheme committed url as a loud TLS error, never a silent local downgrade', function (): void {
    // committedUrl() reads the value raw, so a hand-edited bad-scheme committed url stays
    // non-null: configured() is true (remote intent present) and rawUrl() reaches the
    // RemoteUrl guard in post(), which errors loudly before any request leaves the box.
    putenv('AGENT_MCP_URL');
    File::put(InstallMode::path(), json_encode([
        'mode' => 'cli',
        'version' => 1,
        'url' => 'http://remote.test/agent-mcp',
    ]));

    expect((new RemoteToolClient)->configured())->toBeTrue();

    Http::fake();

    $caught = null;

    try {
        (new RemoteToolClient)->callTool('db_schema', []);
    } catch (RemoteInvocationException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull();
    expect($caught->getMessage())->not->toContain('remote.test');
    expect($caught->getMessage())->not->toContain('http://');
    Http::assertNothingSent();
});

it('throws a generic error carrying neither url nor key when the key is missing', function (): void {
    putenv('AGENT_MCP_URL=https://remote.test/agent-mcp');
    putenv('AGENT_MCP_KEY');

    Http::fake();

    $caught = null;

    try {
        (new RemoteToolClient)->callTool('db_schema', []);
    } catch (RemoteInvocationException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull();
    expect($caught->getMessage())->not->toContain('remote.test');
    expect($caught->getMessage())->not->toContain('https://remote.test/agent-mcp');
    expect($caught->getMessage())->not->toContain('super-secret-key-value');
    Http::assertNothingSent();
});

it('posts a single tools/call with the Bearer header and returns the parsed result', function (): void {
    Http::fake([
        'remote.test/*' => Http::response(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => '{"tables":["users"]}'],
                ],
                'isError' => false,
            ],
        ]), 200),
    ]);

    $result = (new RemoteToolClient)->callTool('db_schema', ['table' => 'users']);

    expect($result['isError'])->toBeFalse();
    expect($result['content'][0]['text'])->toBe('{"tables":["users"]}');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://remote.test/agent-mcp'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer super-secret-key-value')
            && $request->hasHeader('Accept', 'application/json, text/event-stream')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('MCP-Protocol-Version')
            && $body['method'] === 'tools/call'
            && $body['params']['name'] === 'db_schema'
            && $body['params']['arguments'] === ['table' => 'users'];
    });

    Http::assertSentCount(1);
});

it('strips a leading SSE data prefix before decoding the result', function (): void {
    Http::fake([
        'remote.test/*' => Http::response('data: '.json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => 'ok'],
                ],
                'isError' => false,
            ],
        ]), 200),
    ]);

    $result = (new RemoteToolClient)->callTool('app_about', []);

    expect($result['content'][0]['text'])->toBe('ok');
});

it('merges paginated tools/list pages by following nextCursor until exhausted', function (): void {
    Http::fakeSequence('remote.test/*')
        ->push(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'tools' => [
                    ['name' => 'db_schema'],
                    ['name' => 'db_query'],
                ],
                'nextCursor' => 'eyJvZmZzZXQiOjE1fQ==',
            ],
        ]), 200)
        ->push(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'tools' => [
                    ['name' => 'app_about'],
                ],
            ],
        ]), 200);

    $tools = (new RemoteToolClient)->listTools();

    expect($tools)->toHaveCount(3);
    expect(array_column($tools, 'name'))->toBe(['db_schema', 'db_query', 'app_about']);

    // The second request must carry the cursor returned by the first page.
    Http::assertSentInOrder([
        function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['method'] === 'tools/list'
                && ! isset($body['params']['cursor']);
        },
        function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['method'] === 'tools/list'
                && $body['params']['cursor'] === 'eyJvZmZzZXQiOjE1fQ==';
        },
    ]);
});

it('throws a generic typed error on a non-2xx response without leaking the body or the key', function (): void {
    Http::fake([
        'remote.test/*' => Http::response('upstream auth failed for super-secret-key-value', 401),
    ]);

    $caught = null;

    try {
        (new RemoteToolClient)->callTool('db_schema', []);
    } catch (RemoteInvocationException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull();
    expect($caught->getMessage())->toContain('401');
    expect($caught->getMessage())->not->toContain('super-secret-key-value');
    expect($caught->getMessage())->not->toContain('upstream auth failed');
    expect($caught->getMessage())->not->toContain('Bearer');
});

it('throws a generic typed error on a transport failure without leaking the key', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('cURL error 6: could not resolve host super-secret-key-value');
    });

    $caught = null;

    try {
        (new RemoteToolClient)->listTools();
    } catch (RemoteInvocationException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull();
    expect($caught->getMessage())->not->toContain('super-secret-key-value');
    expect($caught->getMessage())->not->toContain('Bearer');
    expect($caught->getMessage())->not->toContain('cURL');
});

it('throws a generic typed error when the result envelope is missing or malformed', function (): void {
    Http::fake([
        'remote.test/*' => Http::response('not json at all', 200),
    ]);

    expect(fn () => (new RemoteToolClient)->callTool('db_schema', []))
        ->toThrow(RemoteInvocationException::class);
});

it('surfaces a remote JSON-RPC error (disabled or unknown tool) with the protocol message, never the key', function (): void {
    // The server returns a JSON-RPC error envelope for a tool that is disabled or not
    // registered. The CLI must surface that protocol message (it names the tool, useful
    // for the caller) and must never leak the key.
    Http::fake([
        'remote.test/*' => Http::response(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => ['code' => -32602, 'message' => 'Tool [cache_keys] not found.'],
        ]), 200),
    ]);

    $caught = null;

    try {
        (new RemoteToolClient)->callTool('cache_keys', []);
    } catch (RemoteInvocationException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull();
    expect($caught->getMessage())->toContain('cache_keys');
    expect($caught->getMessage())->not->toContain('missing a result envelope');
    expect($caught->getMessage())->not->toContain('super-secret-key-value');
    expect($caught->getMessage())->not->toContain('Bearer');
});
