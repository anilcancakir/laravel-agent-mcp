<?php

use Anilcancakir\LaravelAgentMcp\Database\CatalogQuery;
use Anilcancakir\LaravelAgentMcp\Tools\DbDiagnosticsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;
use Mockery\Expectation;

// Inline stub server hosting only DbDiagnosticsTool, isolated from AgentMcpServer.

/**
 * Inline stub server that hosts DbDiagnosticsTool for this test file only.
 */
final class DbDiagnosticsStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbDiagnosticsTool::class,
    ];
}

beforeEach(function (): void {
    app()->register(McpServiceProvider::class);

    // db_diagnostics is OFF by default; the behavioral tests enable it.
    config()->set('agent-mcp.tools.db_diagnostics', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);
});

// --- tool-enabled gate (default OFF, one flag for both sections) ---

it('denies the call when db_diagnostics is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_diagnostics', false);

    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, [])
        ->assertHasErrors();

    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, ['section' => 'locks'])
        ->assertHasErrors();
});

it('is annotated read-only and named db_diagnostics', function (): void {
    $tool = app(DbDiagnosticsTool::class);

    expect($tool->name())->toBe('db_diagnostics')
        ->and($tool->toArray()['annotations'])->toHaveKey('readOnlyHint', true)
        ->and(mb_strlen($tool->description()))->toBeLessThanOrEqual(1500);
});

// --- section validation ---

it('rejects an invalid section with a message naming the valid sections', function (): void {
    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, ['section' => 'bogus'])
        ->assertHasErrors()
        ->assertSee('slow_queries')
        ->assertSee('locks')
        ->assertDontSee('bogus');
});

// --- SQLite has neither store: available:false for both sections ---

it('returns available:false on SQLite for the default slow_queries section', function (): void {
    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, [])
        ->assertOk()
        ->assertSee('"available":false')
        ->assertSee('reason')
        ->assertSee('sqlite');
});

it('returns available:false on SQLite for the locks section', function (): void {
    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, ['section' => 'locks'])
        ->assertOk()
        ->assertSee('"available":false')
        ->assertSee('reason')
        ->assertSee('sqlite');
});

// --- PostgreSQL branches via an injected fake CatalogQuery ---
//
// The slow_queries branch probes pg_extension first, then reads the statements, so the
// fake branches on the SQL string (the probe finds the extension, the data query returns
// one canned row).

it('returns available:false when pg_stat_statements is not installed', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('pgsql');
    // The probe is the only select() issued on this path, so a flat empty return suffices.
    $fake->shouldReceive('select')->andReturn([]);
    app()->instance(CatalogQuery::class, $fake);

    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, ['section' => 'slow_queries'])
        ->assertOk()
        ->assertSee('"available":false')
        ->assertSee('pg_stat_statements');
});

it('maps pg_stat_statements rows into a slow-statement payload on PostgreSQL', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('pgsql');
    /** @var Expectation $selectExpectation */
    $selectExpectation = $fake->shouldReceive('select');
    $selectExpectation->andReturnUsing(function (string $sql): array {
        if (str_contains($sql, 'pg_extension')) {
            return [(object) ['?column?' => 1]];
        }

        return [
            (object) [
                'query' => 'SELECT * FROM reports WHERE heavy = true',
                'calls' => 42,
                'total_exec_time' => 1234.5678,
                'mean_exec_time' => 29.3,
                'rows' => 8400,
            ],
        ];
    });
    app()->instance(CatalogQuery::class, $fake);

    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, ['section' => 'slow_queries'])
        ->assertOk()
        ->assertSee('pgsql')
        ->assertSee('pg_stat_statements')
        ->assertSee('"statements"')
        ->assertSee('"mean_ms":29.3')
        ->assertSee('total_ms')
        ->assertSee('reports');
});

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

    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, ['section' => 'locks'])
        ->assertOk()
        ->assertSee('pgsql')
        ->assertSee('pg_locks')
        ->assertSee('"blocked_sessions"')
        ->assertSee('"blocked_pid":4242')
        ->assertSee('"blocking_pid":1717')
        ->assertSee('batch_job');
});

// --- MySQL branches via an injected fake CatalogQuery ---

it('maps performance_schema digest rows into a slow-statement payload on MySQL', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('mysql');
    $fake->shouldReceive('mysqlDatabaseScope')->andReturn('SCHEMA_NAME = DATABASE()');
    $fake->shouldReceive('select')->andReturn([
        (object) [
            'query' => 'SELECT * FROM `orders` WHERE `total` > ?',
            'calls' => 17,
            'avg_picoseconds' => 5_000_000_000,
            'sum_picoseconds' => 85_000_000_000,
            'rows_sent' => 510,
        ],
    ]);
    app()->instance(CatalogQuery::class, $fake);

    // 5e9 picoseconds / 1e9 = 5 ms; 85e9 / 1e9 = 85 ms.
    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, ['section' => 'slow_queries', 'limit' => 5])
        ->assertOk()
        ->assertSee('mysql')
        ->assertSee('performance_schema')
        ->assertSee('"mean_ms":5')
        ->assertSee('"total_ms":85')
        ->assertSee('"rows_sent":510');
});

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

    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, ['section' => 'locks'])
        ->assertOk()
        ->assertSee('mysql')
        ->assertSee('PROCESSLIST')
        ->assertSee('"process_id":909')
        ->assertSee('"lock_waiting_sessions"')
        ->assertSee('metadata lock');
});

// --- unsupported engine degrades to available:false on both sections ---

it('returns available:false for an engine it does not understand', function (string $section): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('sqlsrv');
    app()->instance(CatalogQuery::class, $fake);

    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, ['section' => $section])
        ->assertOk()
        ->assertSee('"available":false')
        ->assertSee('reason');
})->with([
    'slow_queries',
    'locks',
]);

// --- limit clamp: the bound LIMIT is always 1..100, 20 when absent ---

it('clamps the slow_queries limit into 1..100', function (?int $requested, int $expected): void {
    $bound = null;

    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('pgsql');
    /** @var Expectation $selectExpectation */
    $selectExpectation = $fake->shouldReceive('select');
    $selectExpectation->andReturnUsing(function (string $sql, array $bindings = []) use (&$bound): array {
        if (str_contains($sql, 'pg_extension')) {
            return [(object) ['?column?' => 1]];
        }

        $bound = $bindings;

        return [];
    });
    app()->instance(CatalogQuery::class, $fake);

    $arguments = ['section' => 'slow_queries'];

    if ($requested !== null) {
        $arguments['limit'] = $requested;
    }

    DbDiagnosticsStubServer::tool(DbDiagnosticsTool::class, $arguments)->assertOk();

    expect($bound)->toBe([$expected]);
})->with([
    'above the cap' => [500, 100],
    'at the cap' => [100, 100],
    'inside the range' => [7, 7],
    'zero' => [0, 1],
    'negative' => [-5, 1],
    'absent' => [null, 20],
]);

afterEach(function (): void {
    Mockery::close();
});
