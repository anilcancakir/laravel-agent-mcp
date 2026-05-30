<?php

use Anilcancakir\LaravelAgentMcp\Tools\InspectRouteTool;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only InspectRouteTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts InspectRouteTool for this test file only.
 */
final class InspectRouteStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        InspectRouteTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.inspect_route', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Register fixture routes with different characteristics.
    Route::get('/inspect-open', fn () => 'open')
        ->name('inspect.open');

    Route::get('/inspect-auth/{id}', fn () => 'auth')
        ->middleware('auth')
        ->name('inspect.auth')
        ->where('id', '[0-9]+');
});

// --- tool-enabled gate ---

it('denies the call when inspect_route is disabled in config', function (): void {
    config()->set('agent-mcp.tools.inspect_route', false);

    InspectRouteStubServer::tool(InspectRouteTool::class, ['name' => 'inspect.open'])
        ->assertHasErrors();
});

// --- find by name ---

it('finds a route by name and returns its detail', function (): void {
    $response = InspectRouteStubServer::tool(InspectRouteTool::class, ['name' => 'inspect.open'])
        ->assertOk();

    $response->assertSee('inspect.open');
    $response->assertSee('middleware_resolved');
});

// --- find by uri ---

it('finds a route by uri and returns its detail', function (): void {
    $response = InspectRouteStubServer::tool(InspectRouteTool::class, ['uri' => 'inspect-open'])
        ->assertOk();

    $response->assertSee('inspect.open');
});

// --- route with wheres ---

it('includes wheres constraints in the route detail', function (): void {
    $response = InspectRouteStubServer::tool(InspectRouteTool::class, ['name' => 'inspect.auth'])
        ->assertOk();

    $response->assertSee('wheres');
    $response->assertSee('[0-9]+');
});

// --- route with middleware ---

it('includes middleware_resolved for a route with middleware', function (): void {
    $response = InspectRouteStubServer::tool(InspectRouteTool::class, ['name' => 'inspect.auth'])
        ->assertOk();

    $response->assertSee('middleware_resolved');
});

// --- defaults field ---

it('includes a defaults field in the route detail', function (): void {
    $response = InspectRouteStubServer::tool(InspectRouteTool::class, ['name' => 'inspect.open'])
        ->assertOk();

    $response->assertSee('defaults');
});

// --- not found ---

it('returns an error when route is not found by name', function (): void {
    InspectRouteStubServer::tool(InspectRouteTool::class, ['name' => 'nonexistent.route'])
        ->assertHasErrors();
});

it('returns an error when neither name nor uri is given', function (): void {
    InspectRouteStubServer::tool(InspectRouteTool::class, [])
        ->assertHasErrors();
});

// --- controller never instantiated ---

it('returns controller_exists flag without instantiating the controller', function (): void {
    $response = InspectRouteStubServer::tool(InspectRouteTool::class, ['name' => 'inspect.open'])
        ->assertOk();

    // is_closure should be true for this closure route.
    $response->assertSee('is_closure');
});
