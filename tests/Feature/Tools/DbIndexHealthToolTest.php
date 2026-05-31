<?php

use Anilcancakir\LaravelAgentMcp\Database\CatalogQuery;
use Anilcancakir\LaravelAgentMcp\Tools\DbIndexHealthTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;
use Mockery\Expectation;

// A minimal server that hosts only DbIndexHealthTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts DbIndexHealthTool for this test file only.
 */
final class DbIndexHealthStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbIndexHealthTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.db_index_health', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build the fixture schema on the readonly connection BEFORE the tool hardens
    // it (ReadonlyConnectionResolver sets PRAGMA query_only=ON on first access).
    Schema::connection('readonly')->dropIfExists('index_health_posts');
    Schema::connection('readonly')->create('index_health_posts', function (Blueprint $table): void {
        $table->id();
        $table->string('title')->index();
        $table->string('slug')->unique();
        $table->timestamps();
    });
});

// --- tool-enabled gate ---

it('denies the call when the tool is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_index_health', false);

    DbIndexHealthStubServer::tool(DbIndexHealthTool::class, [])
        ->assertHasErrors();
});

// --- SQLite path: index list ---

it('returns the index list for a known table on SQLite', function (): void {
    $response = DbIndexHealthStubServer::tool(DbIndexHealthTool::class, ['table' => 'index_health_posts'])
        ->assertOk();

    // The driver is reported so callers can branch on engine.
    $response->assertSee('sqlite');

    // The unique index on slug must be reflected in the index list.
    $response->assertSee('unique');
});

it('returns index data for every table when no table argument is given on SQLite', function (): void {
    DbIndexHealthStubServer::tool(DbIndexHealthTool::class, [])
        ->assertOk()
        ->assertSee('index_health_posts');
});

// --- unknown table: clean error ---

it('returns a clean error for an unknown table without leaking driver exceptions', function (): void {
    DbIndexHealthStubServer::tool(DbIndexHealthTool::class, ['table' => 'nonexistent_table'])
        ->assertHasErrors();
});

// --- binding safety: a crafted table name cannot alter query structure ---

it('treats a crafted table name as a bound literal, never query structure', function (): void {
    // A crafted name carrying SQL metacharacters and a stacked-statement attempt.
    // It is not a known table, so the tool rejects it cleanly; the fixture table
    // survives, proving the injected DROP never executed.
    $crafted = 'index_health_posts"; DROP TABLE index_health_posts; --';

    DbIndexHealthStubServer::tool(DbIndexHealthTool::class, ['table' => $crafted])
        ->assertHasErrors();

    expect(Schema::connection('readonly')->hasTable('index_health_posts'))->toBeTrue();
});

// --- PG/MySQL branch coverage via an injected fake CatalogQuery ---
//
// The suite runs on SQLite, so the pgsql/mysql branches never execute against a
// real server. We bind a fake CatalogQuery reporting the engine + scope fragments
// and returning canned catalog rows that carry the exact column aliases each branch
// reads, then assert the tool produces the correctly-mapped report.

it('maps the PostgreSQL unused-index + seq-scan catalog rows into the report', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('pgsql');
    $fake->shouldReceive('postgresSchemaScope')->andReturn("n.nspname NOT IN ('pg_catalog', 'information_schema')");
    // The pgsql branch issues three selects (unused indexes, seq-scan advisory,
    // stats-reset). Branch on the SQL string so each returns its canned row set
    // carrying the exact column aliases the tool reads.
    /** @var Expectation $selectExpectation */
    $selectExpectation = $fake->shouldReceive('select');
    $selectExpectation->andReturnUsing(function (string $sql): array {
        if (str_contains($sql, 'pg_stat_user_indexes')) {
            return [
                (object) [
                    'schema' => 'public',
                    'table' => 'orders',
                    'index' => 'orders_legacy_idx',
                    'scans' => 0,
                ],
            ];
        }

        if (str_contains($sql, 'pg_stat_user_tables')) {
            return [
                (object) [
                    'table' => 'orders',
                    'sequential_scans' => 1200,
                    'index_scans' => 30,
                ],
            ];
        }

        return [(object) ['stats_reset' => '2026-05-01 00:00:00+00']];
    });
    app()->instance(CatalogQuery::class, $fake);

    $response = DbIndexHealthStubServer::tool(DbIndexHealthTool::class, [])
        ->assertOk();

    $response->assertSee('pgsql');
    $response->assertSee('unused_indexes');
    $response->assertSee('orders_legacy_idx');
    $response->assertSee('sequential_scan_advisory');
    $response->assertSee('stats_reset');
});

it('maps the MySQL STATISTICS catalog rows into the index report', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('mysql');
    $fake->shouldReceive('mysqlDatabaseScope')->andReturn('TABLE_SCHEMA = DATABASE()');
    $fake->shouldReceive('select')->andReturn([
        (object) [
            'table' => 'invoices',
            'index' => 'invoices_customer_id_index',
            'column' => 'customer_id',
            'seq_in_index' => 1,
            'non_unique' => 1,
            'cardinality' => 5000,
        ],
    ]);
    app()->instance(CatalogQuery::class, $fake);

    $response = DbIndexHealthStubServer::tool(DbIndexHealthTool::class, [])
        ->assertOk();

    $response->assertSee('mysql');
    $response->assertSee('indexes');
    $response->assertSee('invoices_customer_id_index');
    $response->assertSee('customer_id');
    $response->assertSee('cardinality');
});

// --- unsupported engine degrades to available:false ---

it('returns available:false for an engine it does not understand', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('sqlsrv');
    app()->instance(CatalogQuery::class, $fake);

    DbIndexHealthStubServer::tool(DbIndexHealthTool::class, [])
        ->assertOk()
        ->assertSee('available');
});

afterEach(function (): void {
    Mockery::close();
});
