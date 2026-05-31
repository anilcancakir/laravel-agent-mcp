<?php

use Anilcancakir\LaravelAgentMcp\Database\CatalogQuery;
use Anilcancakir\LaravelAgentMcp\Tools\DbTableSizesTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;
use Mockery\Expectation;

// Inline stub server hosting only DbTableSizesTool, isolated from AgentMcpServer.

/**
 * Inline stub server that hosts DbTableSizesTool for this test file only.
 */
final class DbTableSizesStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbTableSizesTool::class,
    ];
}

beforeEach(function (): void {
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.db_table_sizes', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build the fixture on the readonly connection BEFORE hardening (PRAGMA
    // query_only=ON on first resolve).
    Schema::connection('readonly')->dropIfExists('size_fixtures');
    Schema::connection('readonly')->create('size_fixtures', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

// --- tool-enabled gate ---

it('denies the call when db_table_sizes is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_table_sizes', false);

    DbTableSizesStubServer::tool(DbTableSizesTool::class, [])
        ->assertHasErrors();
});

// --- SQLite path returns sizes ---

it('returns table size data on SQLite', function (): void {
    $response = DbTableSizesStubServer::tool(DbTableSizesTool::class, [])
        ->assertOk();

    $response->assertSee('size_fixtures');
    $response->assertSee('total_bytes');
});

// --- engine + measurement-source labelling ---

it('labels the engine and measurement source on SQLite', function (): void {
    $response = DbTableSizesStubServer::tool(DbTableSizesTool::class, [])
        ->assertOk();

    $response->assertSee('sqlite');
    $response->assertSee('source');
});

// --- table arg scoping ---

it('scopes output to a single known table when the table arg is given', function (): void {
    $response = DbTableSizesStubServer::tool(DbTableSizesTool::class, ['table' => 'size_fixtures'])
        ->assertOk();

    $response->assertSee('size_fixtures');
});

// --- unknown table: clean error ---

it('returns a clean error for an unknown table without leaking a driver exception', function (): void {
    DbTableSizesStubServer::tool(DbTableSizesTool::class, ['table' => 'nonexistent_table'])
        ->assertHasErrors();
});

// --- PostgreSQL branch via an injected fake CatalogQuery ---
//
// The suite runs on SQLite, so the pgsql/mysql branches never execute against a
// real server. The fake reports the engine, the scope fragment, and a canned
// pg_stat_user_tables row; we assert the tool maps it (including the dead_pct
// computation) into the expected shape.

it('maps pg_stat_user_tables rows into a sized-table payload on PostgreSQL', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('pgsql');
    $fake->shouldReceive('postgresSchemaScope')->andReturn("schemaname NOT IN ('pg_catalog', 'information_schema')");
    $fake->shouldReceive('select')->andReturn([
        (object) [
            'table_name' => 'public.orders',
            'total_bytes' => 1048576,
            'table_bytes' => 786432,
            'index_bytes' => 262144,
            'live_rows' => 900,
            'dead_rows' => 100,
        ],
    ]);
    app()->instance(CatalogQuery::class, $fake);

    $response = DbTableSizesStubServer::tool(DbTableSizesTool::class, [])
        ->assertOk();

    $response->assertSee('pgsql');
    $response->assertSee('public.orders');
    $response->assertSee('total_bytes');
    $response->assertSee('dead_pct');
    // 100 dead of 1000 total tuples => 10%.
    $response->assertSee('10');
});

// --- MySQL branch via an injected fake CatalogQuery ---

it('maps information_schema.TABLES rows into a sized-table payload on MySQL', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('mysql');
    $fake->shouldReceive('mysqlDatabaseScope')->andReturn('TABLE_SCHEMA = DATABASE()');
    $fake->shouldReceive('select')->andReturn([
        (object) [
            'table_name' => 'invoices',
            'data_bytes' => 524288,
            'index_bytes' => 131072,
            'total_bytes' => 655360,
            'estimated_rows' => 4200,
            'free_bytes' => 8192,
        ],
    ]);
    app()->instance(CatalogQuery::class, $fake);

    $response = DbTableSizesStubServer::tool(DbTableSizesTool::class, [])
        ->assertOk();

    $response->assertSee('mysql');
    $response->assertSee('information_schema.TABLES');
    $response->assertSee('invoices');
    $response->assertSee('estimated_rows');
    $response->assertSee('4200');
});

// --- SQLite degrade path: dbstat not compiled in ---
//
// When the dbstat probe throws, the tool degrades to page_count * page_size. The
// fake throws on the dbstat probe and returns the page metrics for the degrade.

it('degrades to the whole-file size when dbstat is not compiled in', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('sqlite');
    $fake->shouldReceive('knownTables')->andReturn([]);
    // The dbstat probe must throw (no dbstat virtual table in this build); the
    // degrade then reads page_count and page_size. Branch on the SQL string.
    /** @var Expectation $selectExpectation */
    $selectExpectation = $fake->shouldReceive('select');
    $selectExpectation->andReturnUsing(function (string $sql): array {
        if (str_contains($sql, 'dbstat')) {
            throw new RuntimeException('no such table: dbstat');
        }

        if ($sql === 'PRAGMA page_count') {
            return [(object) ['page_count' => 40]];
        }

        return [(object) ['page_size' => 4096]];
    });
    app()->instance(CatalogQuery::class, $fake);

    $response = DbTableSizesStubServer::tool(DbTableSizesTool::class, [])
        ->assertOk();

    $response->assertSee('sqlite');
    $response->assertSee('database_total_bytes');
    // 40 pages * 4096 bytes = 163840.
    $response->assertSee('163840');
});

afterEach(function (): void {
    Mockery::close();
});
