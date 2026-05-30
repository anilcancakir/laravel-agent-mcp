<?php

use Anilcancakir\LaravelAgentMcp\AgentMcpServiceProvider;
use Anilcancakir\LaravelAgentMcp\Server\AgentMcpServer;
use Anilcancakir\LaravelAgentMcp\Tools\DbQueryTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbRawSelectTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbSchemaTool;
use Anilcancakir\LaravelAgentMcp\Tools\ReadLogsTool;
use Anilcancakir\LaravelAgentMcp\Tools\RunArtisanTool;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Spatie\LaravelPackageTools\Package;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

// These tests prove the security-relevant wiring of the assembled package: the
// /agent-mcp route is fail-closed behind KeyAuthMiddleware (401 with no key, 401 with
// the wrong key, 200 with the correct Bearer key), auto_register=false registers
// nothing, and a thrown tool error never carries a stack trace over HTTP even with
// app.debug=true (StripsErrorTraces). Authentication is a single server-admin key:
// there is no user, no Sanctum, no per-caller ability.

/**
 * Build a JSON-RPC tools/list payload.
 *
 * @return array<string, mixed>
 */
function toolsListPayload(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => new stdClass,
    ];
}

it('declares the five package tools on the server', function (): void {
    $tools = (function (): array {
        return $this->tools;
    })->call(new AgentMcpServer(new FakeTransporter));

    expect($tools)->toBe([
        DbSchemaTool::class,
        DbQueryTool::class,
        DbRawSelectTool::class,
        ReadLogsTool::class,
        RunArtisanTool::class,
    ]);
});

it('registers a default audit channel when the operator has not defined one', function (): void {
    // Audit is on by default; without a defined channel the LogManager silently falls
    // back to the emergency logger on every tool call. The provider must define a sane
    // default so the audit trail works out of the box.
    expect(config('logging.channels.agent-mcp-audit'))->toBeArray();
    expect(config('logging.channels.agent-mcp-audit.driver'))->toBe('single');
});

it('rejects a request with no key to the MCP route with 401 (fail closed)', function (): void {
    config()->set('agent-mcp.enabled', true);
    config()->set('agent-mcp.auto_register', true);
    config()->set('agent-mcp.key', 'the-server-admin-key');

    postJson('/agent-mcp', toolsListPayload())
        ->assertStatus(401);
});

it('rejects a request with the wrong key with 401', function (): void {
    config()->set('agent-mcp.enabled', true);
    config()->set('agent-mcp.auto_register', true);
    config()->set('agent-mcp.key', 'the-server-admin-key');

    withHeaders(['Authorization' => 'Bearer wrong-key'])
        ->postJson('/agent-mcp', toolsListPayload())
        ->assertStatus(401);
});

it('is fail-closed when the key is unset even with a Bearer header', function (): void {
    config()->set('agent-mcp.enabled', true);
    config()->set('agent-mcp.auto_register', true);
    config()->set('agent-mcp.key', null);

    withHeaders(['Authorization' => 'Bearer anything'])
        ->postJson('/agent-mcp', toolsListPayload())
        ->assertStatus(401);
});

it('lists the enabled tools for a request carrying the correct Bearer key', function (): void {
    config()->set('agent-mcp.enabled', true);
    config()->set('agent-mcp.auto_register', true);
    config()->set('agent-mcp.key', 'the-server-admin-key');

    $response = withHeaders(['Authorization' => 'Bearer the-server-admin-key'])
        ->postJson('/agent-mcp', toolsListPayload())
        ->assertOk();

    /** @var array<int, array<string, mixed>> $tools */
    $tools = $response->json('result.tools');
    $names = array_map(static fn (array $tool): mixed => $tool['name'] ?? null, $tools);

    expect($names)->toContain('db_schema', 'db_query', 'db_raw_select', 'read_logs');
});

it('registers no MCP route when auto_register is false', function (): void {
    // Route registration is the packageBooted() gate: enabled AND auto_register. Drive that
    // branch directly against a CLEAN Mcp registrar (the global app already registered the
    // route at its default-true boot) so the assertion reflects only this provider's
    // decision under auto_register=false: it must register no web server for the route.
    config()->set('agent-mcp.enabled', true);
    config()->set('agent-mcp.auto_register', false);

    app()->instance(Registrar::class, new Registrar);

    $provider = new AgentMcpServiceProvider(app());
    $provider->configurePackage(new Package);
    $provider->packageBooted();

    expect(app(Registrar::class)->getWebServer(config('agent-mcp.route')))->toBeNull();
});

it('never leaks a stack trace in an MCP error response even with app.debug true', function (): void {
    config()->set('agent-mcp.enabled', true);
    config()->set('agent-mcp.auto_register', true);
    config()->set('agent-mcp.key', 'the-server-admin-key');
    config()->set('app.debug', true);

    // Force a non-Auth, non-Validation Throwable from inside a tool's handle(): it
    // bubbles past CallTool into Server::handle(), whose stock behavior re-throws the
    // raw exception (full trace) when app.debug is true. StripsErrorTraces must catch
    // that and emit a generic JSON-RPC error instead.
    $payload = [
        'jsonrpc' => '2.0',
        'id' => 9,
        'method' => 'tools/call',
        'params' => [
            'name' => 'db_schema',
            'arguments' => ['table' => ['not', 'a', 'string']],
        ],
    ];

    $response = withHeaders(['Authorization' => 'Bearer the-server-admin-key'])
        ->postJson('/agent-mcp', $payload);

    $body = $response->getContent();

    expect($body)->not->toContain('#0 ');
    expect($body)->not->toContain('Stack trace');
    expect($body)->not->toContain(base_path());
    expect(strtolower((string) $body))->not->toContain('.php(');
});
