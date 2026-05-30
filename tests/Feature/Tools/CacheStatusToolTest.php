<?php

use Anilcancakir\LaravelAgentMcp\Tools\CacheStatusTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only CacheStatusTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts CacheStatusTool for this test file only.
 */
final class CacheStatusStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        CacheStatusTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.cache_status', true);
    config()->set('agent-mcp.audit.enabled', false);
});

// --- tool-enabled gate ---

it('denies the call when cache_status is disabled in config', function (): void {
    config()->set('agent-mcp.tools.cache_status', false);

    CacheStatusStubServer::tool(CacheStatusTool::class, [])
        ->assertHasErrors();
});

// --- stores + default + prefix ---

it('reports the configured stores, default store and prefix', function (): void {
    config()->set('cache.default', 'array');
    config()->set('cache.prefix', 'agentmcp_cache_');

    $response = CacheStatusStubServer::tool(CacheStatusTool::class, [])
        ->assertOk();

    $response->assertSee('stores');
    $response->assertSee('array');
    $response->assertSee('default');
    $response->assertSee('agentmcp_cache_');
});

// --- optimization state ---

it('reports the framework optimization state (config/routes/events cached)', function (): void {
    $response = CacheStatusStubServer::tool(CacheStatusTool::class, [])
        ->assertOk();

    $response->assertSee('optimization');
    $response->assertSee('config_cached');
    $response->assertSee('routes_cached');
    $response->assertSee('events_cached');
});

// --- opcache section ---

it('reports an opcache section', function (): void {
    $response = CacheStatusStubServer::tool(CacheStatusTool::class, [])
        ->assertOk();

    $response->assertSee('opcache');
});

// --- session overlap risk ---

it('flags session overlap risk when session uses redis on the cache connection', function (): void {
    config()->set('session.driver', 'redis');
    config()->set('session.connection', 'default');
    config()->set('cache.default', 'redis');
    config()->set('cache.stores.redis', [
        'driver' => 'redis',
        'connection' => 'default',
    ]);

    $response = CacheStatusStubServer::tool(CacheStatusTool::class, [])
        ->assertOk();

    $response->assertSee('session_overlap_risk');
});

it('does not flag session overlap risk when session does not use redis', function (): void {
    config()->set('session.driver', 'file');
    config()->set('cache.default', 'array');

    $response = CacheStatusStubServer::tool(CacheStatusTool::class, [])
        ->assertOk();

    // session_overlap_risk must be present as a key but false.
    $response->assertSee('session_overlap_risk');
    $response->assertSee('false');
});
