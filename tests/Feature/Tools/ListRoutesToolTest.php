<?php

use Anilcancakir\LaravelAgentMcp\Tools\ListRoutesTool;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only ListRoutesTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts ListRoutesTool for this test file only.
 */
final class ListRoutesStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListRoutesTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.list_routes', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Register fixture routes so the tool has something to inspect.
    Route::get('/test-open', fn () => 'open')
        ->name('test.open');

    Route::get('/test-auth', fn () => 'auth')
        ->middleware('auth')
        ->name('test.auth');

    Route::get('/test-web', fn () => 'web')
        ->middleware('web')
        ->name('test.web');
});

// --- tool-enabled gate ---

it('denies the call when list_routes is disabled in config', function (): void {
    config()->set('agent-mcp.tools.list_routes', false);

    ListRoutesStubServer::tool(ListRoutesTool::class, [])
        ->assertHasErrors();
});

// --- basic listing ---

it('returns a list of routes with required fields', function (): void {
    $response = ListRoutesStubServer::tool(ListRoutesTool::class, [])
        ->assertOk();

    $response->assertSee('test.open');
    $response->assertSee('methods');
    $response->assertSee('uri');
});

it('includes resolved middleware in the route rows', function (): void {
    $response = ListRoutesStubServer::tool(ListRoutesTool::class, [])
        ->assertOk();

    $response->assertSee('middleware_resolved');
});

it('flags routes_are_cached in metadata', function (): void {
    $response = ListRoutesStubServer::tool(ListRoutesTool::class, [])
        ->assertOk();

    $response->assertSee('routes_are_cached');
});

// --- method filter ---

it('filters routes by HTTP method', function (): void {
    $response = ListRoutesStubServer::tool(ListRoutesTool::class, ['method' => 'GET'])
        ->assertOk();

    $response->assertSee('test.open');
});

// --- uri_prefix filter ---

it('filters routes by uri_prefix', function (): void {
    $response = ListRoutesStubServer::tool(ListRoutesTool::class, ['uri_prefix' => 'test-auth'])
        ->assertOk();

    $response->assertSee('test.auth');
});

// --- name_pattern filter ---

it('filters routes by name_pattern', function (): void {
    $response = ListRoutesStubServer::tool(ListRoutesTool::class, ['name_pattern' => 'test.*'])
        ->assertOk();

    $response->assertSee('test.open');
});

// --- middleware filter ---

it('filters routes by middleware name', function (): void {
    $response = ListRoutesStubServer::tool(ListRoutesTool::class, ['middleware' => 'auth'])
        ->assertOk();

    $response->assertSee('test.auth');
});

// --- exclude_middleware filter ---

it('excludes routes that have a given middleware (exclude_middleware)', function (): void {
    // The auth route should be absent; the open route should be present.
    ListRoutesStubServer::tool(ListRoutesTool::class, ['exclude_middleware' => 'auth'])
        ->assertOk()
        ->assertSee('test.open')
        ->assertDontSee('test.auth');
});

// --- only_fallback filter ---

it('only_fallback true returns no routes when no fallback is registered', function (): void {
    // "count" must be zero: no fallback routes are registered in this test.
    ListRoutesStubServer::tool(ListRoutesTool::class, ['only_fallback' => true])
        ->assertOk()
        ->assertSee('"count": 0');
});
