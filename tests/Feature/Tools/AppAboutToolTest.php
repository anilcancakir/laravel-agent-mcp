<?php

use Anilcancakir\LaravelAgentMcp\Tools\AppAboutTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server whose only registered tool is AppAboutTool, keeping these
// tests isolated from AgentMcpServer.

/**
 * Inline stub server that hosts AppAboutTool for this test file only.
 */
final class AppAboutStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        AppAboutTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's own provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.app_about', true);
    config()->set('agent-mcp.audit.enabled', false);
});

// --- tool-enabled gate ---

it('denies the call when app_about is disabled in config', function (): void {
    config()->set('agent-mcp.tools.app_about', false);

    AppAboutStubServer::tool(AppAboutTool::class, [])
        ->assertHasErrors();
});

// --- full output without sections filter ---

it('returns environment, cache, drivers, opcache, and extensions sections', function (): void {
    $response = AppAboutStubServer::tool(AppAboutTool::class, [])
        ->assertOk();

    // Core environment fields.
    $response->assertSee('environment');
    $response->assertSee('php_version');
    $response->assertSee('laravel_version');

    // Cache optimization flags.
    $response->assertSee('configuration_cached');
    $response->assertSee('routes_cached');
    $response->assertSee('events_cached');

    // Drivers section.
    $response->assertSee('drivers');

    // Extensions section.
    $response->assertSee('extensions');
});

// --- sections filter ---

it('returns only the requested sections when sections arg is given', function (): void {
    $response = AppAboutStubServer::tool(AppAboutTool::class, ['sections' => ['environment']])
        ->assertOk();

    $response->assertSee('environment');
    // Drivers should not be present when only environment requested.
    $response->assertDontSee('"drivers"');
});

// --- drivers section ---

it('reports driver names in the drivers section', function (): void {
    config()->set('cache.default', 'array');
    config()->set('queue.default', 'sync');

    $response = AppAboutStubServer::tool(AppAboutTool::class, ['sections' => ['drivers']])
        ->assertOk();

    $response->assertSee('cache');
    $response->assertSee('queue');
    $response->assertSee('database');
});

// --- extensions section ---

it('lists loaded PHP extensions', function (): void {
    $response = AppAboutStubServer::tool(AppAboutTool::class, ['sections' => ['extensions']])
        ->assertOk();

    // Core is always loaded.
    $response->assertSee('Core');
});
