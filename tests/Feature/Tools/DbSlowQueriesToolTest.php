<?php

use Anilcancakir\LaravelAgentMcp\Tools\DbSlowQueriesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// Inline stub server hosting only DbSlowQueriesTool, isolated from AgentMcpServer.

/**
 * Inline stub server that hosts DbSlowQueriesTool for this test file only.
 */
final class DbSlowQueriesStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbSlowQueriesTool::class,
    ];
}

beforeEach(function (): void {
    app()->register(McpServiceProvider::class);

    // db_slow_queries is OFF by default (Step 1); the behavioral tests enable it.
    config()->set('agent-mcp.tools.db_slow_queries', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);
});

// --- tool-enabled gate (default OFF) ---

it('denies the call when db_slow_queries is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_slow_queries', false);

    DbSlowQueriesStubServer::tool(DbSlowQueriesTool::class, [])
        ->assertHasErrors();
});

// --- SQLite has no slow-query store: available:false ---

it('returns available:false cleanly on SQLite', function (): void {
    $response = DbSlowQueriesStubServer::tool(DbSlowQueriesTool::class, [])
        ->assertOk();

    $response->assertSee('available');
    $response->assertSee('reason');
    $response->assertSee('sqlite');
});
