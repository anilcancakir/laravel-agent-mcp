<?php

use Anilcancakir\LaravelAgentMcp\Cli\RemoteInvocationException;
use Anilcancakir\LaravelAgentMcp\Cli\RemoteToolClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// RemoteToolClient is the remote-mode HTTP surface the CLI composes. It POSTs a single
// stateless JSON-RPC tools/call (no initialize handshake), follows tools/list pagination
// via nextCursor, and mirrors StdioBridgeCommand's scrub discipline: the key only ever
// travels in the Authorization header, never in a thrown message or any echoed output.

beforeEach(function (): void {
    putenv('AGENT_MCP_URL=https://remote.test/agent-mcp');
    putenv('AGENT_MCP_KEY=super-secret-key-value');
});

afterEach(function (): void {
    putenv('AGENT_MCP_URL');
    putenv('AGENT_MCP_KEY');
});

it('reports configured true only when both env vars are present', function (): void {
    expect((new RemoteToolClient)->configured())->toBeTrue();

    putenv('AGENT_MCP_KEY');
    expect((new RemoteToolClient)->configured())->toBeFalse();

    putenv('AGENT_MCP_KEY=super-secret-key-value');
    putenv('AGENT_MCP_URL');
    expect((new RemoteToolClient)->configured())->toBeFalse();
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
