<?php

use Anilcancakir\LaravelAgentMcp\Database\CatalogQuery;
use Anilcancakir\LaravelAgentMcp\Tools\DbActiveLocksTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// Inline stub server hosting only DbActiveLocksTool, isolated from AgentMcpServer.

/**
 * Inline stub server that hosts DbActiveLocksTool for this test file only.
 */
final class DbActiveLocksStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbActiveLocksTool::class,
    ];
}

beforeEach(function (): void {
    app()->register(McpServiceProvider::class);

    // db_active_locks is OFF by default (Step 1); the behavioral tests enable it.
    config()->set('agent-mcp.tools.db_active_locks', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);
});

// --- tool-enabled gate (default OFF) ---

it('denies the call when db_active_locks is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_active_locks', false);

    DbActiveLocksStubServer::tool(DbActiveLocksTool::class, [])
        ->assertHasErrors();
});

// --- SQLite has no server-side lock catalog: available:false ---

it('returns available:false cleanly on SQLite', function (): void {
    $response = DbActiveLocksStubServer::tool(DbActiveLocksTool::class, [])
        ->assertOk();

    $response->assertSee('available');
    $response->assertSee('reason');
    $response->assertSee('sqlite');
});

// --- PostgreSQL branch via an injected fake CatalogQuery ---
//
// The suite runs on SQLite, so the pgsql/mysql branches never execute against a
// real server. We bind a fake CatalogQuery whose driver() reports the engine and
// whose select() returns canned catalog rows carrying the exact column aliases the
// tool reads, then assert the tool maps those rows into the correct payload shape.

it('maps the PostgreSQL pg_locks join into a blocked-session payload', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('pgsql');
    $fake->shouldReceive('select')->andReturn([
        (object) [
            'blocked_pid' => 4242,
            'blocked_user' => 'app_writer',
            'blocked_query' => 'UPDATE orders SET status = ?',
            'blocking_pid' => 1717,
            'blocking_user' => 'batch_job',
            'blocking_query' => 'UPDATE orders SET total = ?',
        ],
    ]);
    app()->instance(CatalogQuery::class, $fake);

    $response = DbActiveLocksStubServer::tool(DbActiveLocksTool::class, [])
        ->assertOk();

    // Engine label + the mapped blocked/blocking identities.
    $response->assertSee('pgsql');
    $response->assertSee('pg_locks');
    $response->assertSee('blocked_pid');
    $response->assertSee('4242');
    $response->assertSee('blocking_pid');
    $response->assertSee('1717');
    $response->assertSee('batch_job');
});

// --- MySQL branch via an injected fake CatalogQuery ---

it('maps the MySQL PROCESSLIST rows into a lock-waiting-session payload', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('mysql');
    $fake->shouldReceive('mysqlDatabaseScope')->andReturn('DB = DATABASE()');
    $fake->shouldReceive('select')->andReturn([
        (object) [
            'process_id' => 909,
            'user' => 'app_user',
            'command' => 'Query',
            'seconds' => 12,
            'state' => 'Waiting for table metadata lock',
            'query' => 'ALTER TABLE invoices ADD COLUMN note TEXT',
        ],
    ]);
    app()->instance(CatalogQuery::class, $fake);

    $response = DbActiveLocksStubServer::tool(DbActiveLocksTool::class, [])
        ->assertOk();

    $response->assertSee('mysql');
    $response->assertSee('PROCESSLIST');
    $response->assertSee('process_id');
    $response->assertSee('909');
    $response->assertSee('lock_waiting_sessions');
    $response->assertSee('metadata lock');
});

// --- unsupported engine degrades to available:false ---

it('returns available:false for an engine it does not understand', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('sqlsrv');
    app()->instance(CatalogQuery::class, $fake);

    $response = DbActiveLocksStubServer::tool(DbActiveLocksTool::class, [])
        ->assertOk();

    $response->assertSee('available');
    $response->assertSee('reason');
});

afterEach(function (): void {
    Mockery::close();
});
