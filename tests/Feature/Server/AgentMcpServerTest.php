<?php

use Anilcancakir\LaravelAgentMcp\AgentMcpServiceProvider;
use Anilcancakir\LaravelAgentMcp\Server\AgentMcpServer;
use Anilcancakir\LaravelAgentMcp\Tools\AppInfoTool;
use Anilcancakir\LaravelAgentMcp\Tools\CacheKeysTool;
use Anilcancakir\LaravelAgentMcp\Tools\CacheTool;
use Anilcancakir\LaravelAgentMcp\Tools\ConfigInspectTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbDiagnosticsTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbHealthTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbQueryTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbRawSelectTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbSchemaTool;
use Anilcancakir\LaravelAgentMcp\Tools\MigrationsStatusTool;
use Anilcancakir\LaravelAgentMcp\Tools\QueueStatusTool;
use Anilcancakir\LaravelAgentMcp\Tools\ReadLogsTool;
use Anilcancakir\LaravelAgentMcp\Tools\RoutesTool;
use Anilcancakir\LaravelAgentMcp\Tools\RunArtisanTool;
use Illuminate\Routing\RouteCollection;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Spatie\LaravelPackageTools\Package;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\json;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withToken;

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

it('declares all package tools on the server', function (): void {
    $tools = (function (): array {
        return $this->tools;
    })->call(new AgentMcpServer(new FakeTransporter));

    expect($tools)->toBe([
        DbSchemaTool::class,
        DbQueryTool::class,
        DbRawSelectTool::class,
        ReadLogsTool::class,
        RunArtisanTool::class,
        MigrationsStatusTool::class,
        DbHealthTool::class,
        DbDiagnosticsTool::class,
        QueueStatusTool::class,
        CacheTool::class,
        CacheKeysTool::class,
        RoutesTool::class,
        AppInfoTool::class,
        ConfigInspectTool::class,
    ]);
});

it('gives every rostered tool its own config flag', function (): void {
    $tools = (function (): array {
        return $this->tools;
    })->call(new AgentMcpServer(new FakeTransporter));

    $names = array_map(static fn (string $tool): string => app($tool)->name(), $tools);

    expect($names)->toEqualCanonicalizing(array_keys(config('agent-mcp.tools')));
});

it('keeps the instructions short and names every rostered tool as a whole backtick token', function (): void {
    // Claude Code truncates server instructions (4,096 characters, older builds 2,048), and
    // with deferred tool loading they are what tells the model when to search for a tool.
    $attributes = (new ReflectionClass(AgentMcpServer::class))->getAttributes(Instructions::class);
    $instructions = $attributes[0]->newInstance()->value;

    expect(strlen($instructions))->toBeLessThanOrEqual(1800);

    $tools = (function (): array {
        return $this->tools;
    })->call(new AgentMcpServer(new FakeTransporter));

    foreach ($tools as $tool) {
        $name = app($tool)->name();

        expect($instructions)->toContain('`'.$name.'`');
    }
});

it('hides a default-OFF tool via shouldRegister when its config flag is false', function (): void {
    config()->set('agent-mcp.tools.config_inspect', false);

    $tool = app(ConfigInspectTool::class);

    expect($tool->shouldRegister())->toBeFalse();
});

it('shows a default-ON tool via shouldRegister when its config flag is true', function (): void {
    config()->set('agent-mcp.tools.routes', true);

    $tool = app(RoutesTool::class);

    expect($tool->shouldRegister())->toBeTrue();
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

    expect($names)->toEqualCanonicalizing([
        'db_schema',
        'db_query',
        'db_raw_select',
        'read_logs',
        'migrations_status',
        'db_health',
        'queue_status',
        'cache',
        'routes',
        'app_info',
    ]);
});

/** Reboot transport registration without leaving the default HTTP routes mounted. */
function registerEndpointForTest(?string $path, ?string $domain = null): void
{
    config([
        'agent-mcp.route' => $path,
        'agent-mcp.domain' => $domain,
        'agent-mcp.key' => 'the-server-admin-key',
    ]);

    app('router')->setRoutes(new RouteCollection);
    app()->instance(Registrar::class, new Registrar);
    Mcp::clearResolvedInstance(Registrar::class);

    $provider = new AgentMcpServiceProvider(app());
    $provider->configurePackage(new Package);
    $provider->packageBooted();
}

it('isolates every HTTP method to the configured domain', function (string $method): void {
    registerEndpointForTest('private/7f9c2a', 'agents.example.test');

    json($method, 'https://wrong.example.test/private/7f9c2a', toolsListPayload())
        ->assertNotFound();
    json($method, 'https://agents.example.test/agent-mcp', toolsListPayload())
        ->assertNotFound();
    json($method, 'https://agents.example.test/private/7f9c2a', toolsListPayload())
        ->assertStatus($method === 'POST' ? 401 : 405);
})->with([
    'GET',
    'DELETE',
    'POST',
]);

it('normalises surrounding slashes and lists tools on literal custom paths', function (string $path): void {
    registerEndpointForTest('/'.$path.'/', 'agents.example.test');

    withToken('the-server-admin-key')
        ->postJson('https://agents.example.test/'.$path, toolsListPayload())
        ->assertOk()
        ->assertJsonStructure([
            'result' => [
                'tools',
            ],
        ]);
})->with([
    'private/7f9c2a',
    'x',
]);

it('keeps a custom path available on any host when no domain is configured', function (): void {
    registerEndpointForTest('private/7f9c2a');

    foreach (['first.example.test', 'second.example.test'] as $host) {
        withToken('the-server-admin-key')
            ->postJson('https://'.$host.'/private/7f9c2a', toolsListPayload())
            ->assertOk();
    }
});

it('skips HTTP for empty paths without disabling stdio', function (?string $path): void {
    registerEndpointForTest($path);

    expect(app('router')->getRoutes()->getRoutes())->toBe([]);
    expect(app(Registrar::class)->getLocalServer('agent-mcp'))->not->toBeNull();

    postJson('/agent-mcp', toolsListPayload())->assertNotFound();
    postJson('/', toolsListPayload())->assertNotFound();
})->with([
    '',
    '   ',
    '///',
    " / / \t ",
    null,
]);

it('accepts a port in the configured domain', function (): void {
    registerEndpointForTest('private/7f9c2a', 'agents.example.test:9443');

    postJson('https://agents.example.test:9443/private/7f9c2a', toolsListPayload())->assertUnauthorized();
    withToken('the-server-admin-key')
        ->postJson('https://agents.example.test:9443/private/7f9c2a', toolsListPayload())
        ->assertOk();
    getJson('https://wrong.example.test:9443/private/7f9c2a')->assertNotFound();
});

it('preserves endpoint isolation and authentication after a route-cache roundtrip', function (): void {
    registerEndpointForTest('private/7f9c2a', 'agents.example.test:9443');
    $routes = new RouteCollection;

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $route->prepareForSerialization();
        $routes->add($route);
    }

    $cachePath = tempnam(sys_get_temp_dir(), 'agent-mcp-routes-');

    try {
        file_put_contents($cachePath, '<?php return '.var_export($routes->compile(), true).';');
        app('router')->setCompiledRoutes(require $cachePath);

        getJson('https://wrong.example.test/private/7f9c2a')->assertNotFound();
        deleteJson('https://wrong.example.test/private/7f9c2a')->assertNotFound();
        getJson('https://agents.example.test/private/7f9c2a')->assertStatus(405);
        deleteJson('https://agents.example.test/private/7f9c2a')->assertStatus(405);
        postJson('https://agents.example.test/private/7f9c2a', toolsListPayload())
            ->assertUnauthorized();
        withToken('the-server-admin-key')
            ->postJson('https://agents.example.test/private/7f9c2a', toolsListPayload())
            ->assertOk();
        postJson('https://agents.example.test/agent-mcp', toolsListPayload())->assertNotFound();
    } finally {
        unlink($cachePath);
    }
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
