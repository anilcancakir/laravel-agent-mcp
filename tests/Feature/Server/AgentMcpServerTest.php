<?php

declare(strict_types=1);

use Anilcancakir\LaravelAgentMcp\AgentMcpServiceProvider;
use Anilcancakir\LaravelAgentMcp\Server\AgentMcpServer;
use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubTokenUser;
use Anilcancakir\LaravelAgentMcp\Tools\DbQueryTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbRawSelectTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbSchemaTool;
use Anilcancakir\LaravelAgentMcp\Tools\ReadLogsTool;
use Anilcancakir\LaravelAgentMcp\Tools\RunArtisanTool;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Spatie\LaravelPackageTools\Package;

use function Pest\Laravel\postJson;

beforeEach(function (): void {
    // The isolated testbench app does not auto-discover dev dependencies, so the
    // sanctum guard the package's default middleware (auth:sanctum) relies on is not
    // wired. Register the provider and define the guard so the route's auth gate is
    // exercised exactly as it would be in a host app that has sanctum installed.
    app()->register(SanctumServiceProvider::class);

    config()->set('auth.guards.sanctum', [
        'driver' => 'sanctum',
        'provider' => 'users',
    ]);
});

// Step 14 wires the package together: the AgentMcpServer (tool list + name/version/
// instructions), the AgentMcpServiceProvider (config-gated Mcp::web/Mcp::local
// registration + throttle limiter), and StripsErrorTraces (no stack trace ever leaves
// the MCP error path, even with app.debug=true). These tests prove the security-
// relevant wiring: the route requires auth:sanctum, auto_register=false registers
// nothing, and a thrown tool error never carries a trace over HTTP.

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

it('rejects an unauthenticated request to the MCP route with 401', function (): void {
    config()->set('agent-mcp.enabled', true);
    config()->set('agent-mcp.auto_register', true);

    postJson('/mcp', toolsListPayload())
        ->assertStatus(401);
});

it('lists the enabled tools for an authenticated read-ability token', function (): void {
    config()->set('agent-mcp.enabled', true);
    config()->set('agent-mcp.auto_register', true);

    Sanctum::actingAs(
        new StubTokenUser(id: 1, abilities: ['agent-mcp:read']),
        ['agent-mcp:read'],
    );

    $response = postJson('/mcp', toolsListPayload())->assertOk();

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
    config()->set('app.debug', true);

    Sanctum::actingAs(
        new StubTokenUser(id: 1, abilities: ['agent-mcp:read']),
        ['agent-mcp:read'],
    );

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

    $response = postJson('/mcp', $payload);

    $body = $response->getContent();

    expect($body)->not->toContain('#0 ');
    expect($body)->not->toContain('Stack trace');
    expect($body)->not->toContain(base_path());
    expect(strtolower((string) $body))->not->toContain('.php(');
});
