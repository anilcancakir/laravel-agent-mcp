<?php

use Anilcancakir\LaravelAgentMcp\Database\CatalogQuery;
use Anilcancakir\LaravelAgentMcp\Tools\DbSlowQueriesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;
use Mockery\Expectation;

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

    // db_slow_queries is OFF by default; the behavioral tests enable it.
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

// --- PostgreSQL branch via an injected fake CatalogQuery ---
//
// The pgsql branch detects pg_stat_statements first (a pg_extension probe), then
// reads the top statements. The fake returns the extension-present row for the
// probe and a canned statement row for the data query, so the full happy path runs.

it('returns available:false when pg_stat_statements is not installed', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('pgsql');
    // The extension probe returns an empty set: the extension is absent. The probe
    // is the only select() the tool issues on this path, so a flat empty return is
    // sufficient (branching on the SQL would never reach a second query).
    $fake->shouldReceive('select')->andReturn([]);
    app()->instance(CatalogQuery::class, $fake);

    $response = DbSlowQueriesStubServer::tool(DbSlowQueriesTool::class, [])
        ->assertOk();

    $response->assertSee('available');
    $response->assertSee('pg_stat_statements');
});

it('maps pg_stat_statements rows into a slow-statement payload on PostgreSQL', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('pgsql');
    // The tool issues two selects: the pg_extension probe, then the statement query.
    // Branch on the SQL string so the probe finds the extension present and the data
    // query returns one canned row carrying the view's columns.
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

    $response = DbSlowQueriesStubServer::tool(DbSlowQueriesTool::class, [])
        ->assertOk();

    $response->assertSee('pgsql');
    $response->assertSee('pg_stat_statements');
    $response->assertSee('mean_ms');
    $response->assertSee('29.3');
    $response->assertSee('total_ms');
    $response->assertSee('reports');
});

// --- MySQL branch via an injected fake CatalogQuery ---
//
// Timer columns are picoseconds; the tool divides by 1e9 to produce ms. The canned
// avg of 5e9 picoseconds must map to 5.0 ms.

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

    $response = DbSlowQueriesStubServer::tool(DbSlowQueriesTool::class, ['limit' => 5])
        ->assertOk();

    $response->assertSee('mysql');
    $response->assertSee('performance_schema');
    // 5e9 picoseconds / 1e9 = 5 ms; 85e9 / 1e9 = 85 ms.
    $response->assertSee('mean_ms');
    $response->assertSee('rows_sent');
    $response->assertSee('510');
});

afterEach(function (): void {
    Mockery::close();
});
