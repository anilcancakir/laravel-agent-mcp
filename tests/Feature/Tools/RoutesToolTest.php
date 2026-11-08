<?php

use Anilcancakir\LaravelAgentMcp\Tools\RoutesTool;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only RoutesTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts RoutesTool for this test file only.
 */
final class RoutesStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        RoutesTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.routes', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Register fixture routes so the tool has something to read.
    Route::get('/test-open', fn () => 'open')
        ->name('test.open');

    Route::get('/test-auth/{id}', fn () => 'auth')
        ->middleware('auth')
        ->name('test.auth')
        ->where('id', '[0-9]+');

    Route::get('/test-web', fn () => 'web')
        ->middleware('web')
        ->name('test.web');
});

// --- tool-enabled gate ---

it('denies the call when routes is disabled in config', function (): void {
    config()->set('agent-mcp.tools.routes', false);

    RoutesStubServer::tool(RoutesTool::class, [])
        ->assertHasErrors();

    RoutesStubServer::tool(RoutesTool::class, ['name' => 'test.open'])
        ->assertHasErrors();
});

it('is annotated read-only and named routes', function (): void {
    $tool = app(RoutesTool::class);

    expect($tool->name())->toBe('routes')
        ->and($tool->toArray()['annotations'])->toHaveKey('readOnlyHint', true)
        ->and(mb_strlen($tool->description()))->toBeLessThanOrEqual(1500);
});

// --- list mode ---

it('lists routes with the envelope and row keys', function (): void {
    RoutesStubServer::tool(RoutesTool::class, [])
        ->assertOk()
        ->assertSee('routes_are_cached')
        ->assertSee('"count":')
        ->assertSee('test.open')
        ->assertSee('"methods"')
        ->assertSee('"uri"')
        ->assertSee('middleware_resolved');
});

it('emits compact JSON without pretty printing', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['only_fallback' => true])
        ->assertOk()
        ->assertSee('"count":0')
        ->assertDontSee('"count": 0');
});

it('omits defaults from list rows', function (): void {
    RoutesStubServer::tool(RoutesTool::class, [])
        ->assertOk()
        ->assertDontSee('"defaults"');
});

it('filters routes by HTTP method', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['method' => 'post'])
        ->assertOk()
        ->assertDontSee('test.open');

    RoutesStubServer::tool(RoutesTool::class, ['method' => 'get'])
        ->assertOk()
        ->assertSee('test.open');
});

it('filters routes by uri_prefix', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['uri_prefix' => 'test-auth'])
        ->assertOk()
        ->assertSee('test.auth')
        ->assertDontSee('test.open');
});

it('filters routes by name_pattern', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['name_pattern' => 'test.o*'])
        ->assertOk()
        ->assertSee('test.open')
        ->assertDontSee('test.web');
});

it('filters routes by middleware name', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['middleware' => 'auth'])
        ->assertOk()
        ->assertSee('test.auth')
        ->assertDontSee('test.open');
});

it('excludes routes that have a given middleware', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['exclude_middleware' => 'auth'])
        ->assertOk()
        ->assertSee('test.open')
        ->assertDontSee('test.auth');
});

it('only_fallback true returns no routes when no fallback is registered', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['only_fallback' => true])
        ->assertOk()
        ->assertSee('"count":0');
});

// --- detail mode ---

it('returns one route with defaults when selected by name', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['name' => 'test.auth'])
        ->assertOk()
        ->assertSee('"defaults"')
        ->assertSee('"wheres"')
        ->assertSee('[0-9]+')
        ->assertSee('middleware_resolved')
        ->assertSee('"is_closure":true')
        ->assertDontSee('routes_are_cached')
        ->assertDontSee('test.open');
});

it('returns one route with defaults when selected by uri', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['uri' => 'test-open'])
        ->assertOk()
        ->assertSee('"defaults"')
        ->assertSee('test.open')
        ->assertDontSee('test.web');

    RoutesStubServer::tool(RoutesTool::class, ['uri' => '/test-open'])
        ->assertOk()
        ->assertSee('test.open');
});

it('lets the name win when both name and uri are given', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['name' => 'test.web', 'uri' => 'test-open'])
        ->assertOk()
        ->assertSee('test.web')
        ->assertDontSee('test.open');
});

it('matches name and uri exactly instead of by pattern', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['name' => 'test.*'])
        ->assertHasErrors(['Route not found']);

    RoutesStubServer::tool(RoutesTool::class, ['uri' => 'test-'])
        ->assertHasErrors(['Route not found']);
});

it('returns an error when the route is not found', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['name' => 'nonexistent.route'])
        ->assertHasErrors(['Route not found']);

    RoutesStubServer::tool(RoutesTool::class, ['uri' => 'nonexistent/uri'])
        ->assertHasErrors(['Route not found']);
});

it('ignores empty name and uri and falls back to list mode', function (): void {
    RoutesStubServer::tool(RoutesTool::class, ['name' => '', 'uri' => ''])
        ->assertOk()
        ->assertSee('routes_are_cached');
});
