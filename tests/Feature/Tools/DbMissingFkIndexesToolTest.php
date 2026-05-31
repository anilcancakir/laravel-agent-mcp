<?php

use Anilcancakir\LaravelAgentMcp\Database\CatalogQuery;
use Anilcancakir\LaravelAgentMcp\Tools\DbMissingFkIndexesTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only DbMissingFkIndexesTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts DbMissingFkIndexesTool for this test file only.
 */
final class DbMissingFkIndexesStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbMissingFkIndexesTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.db_missing_fk_indexes', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build the fixture schema on the readonly connection BEFORE the tool hardens
    // it (ReadonlyConnectionResolver sets PRAGMA query_only=ON on first access).
    //
    // SQLite foreign keys are only emitted by pragma_foreign_key_list when the
    // column is declared with a REFERENCES clause, so the fixture parent table
    // exists and the child carries a real foreign key WITHOUT an index on it.
    Schema::connection('readonly')->dropIfExists('fk_child');
    Schema::connection('readonly')->dropIfExists('fk_parent');

    Schema::connection('readonly')->create('fk_parent', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::connection('readonly')->create('fk_child', function (Blueprint $table): void {
        $table->id();
        // A real foreign key with NO index on the referencing column: the gap the
        // tool must flag.
        $table->foreignId('fk_parent_id')->constrained('fk_parent');
        $table->string('label');
    });
});

// --- tool-enabled gate ---

it('denies the call when the tool is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_missing_fk_indexes', false);

    DbMissingFkIndexesStubServer::tool(DbMissingFkIndexesTool::class, [])
        ->assertHasErrors();
});

// --- SQLite path: flags the unindexed FK column ---

it('flags an unindexed foreign-key column on SQLite', function (): void {
    $response = DbMissingFkIndexesStubServer::tool(DbMissingFkIndexesTool::class, [])
        ->assertOk();

    // The driver and the heuristic label are reported.
    $response->assertSee('sqlite');
    $response->assertSee('heuristic');

    // The unindexed FK column on the child table is flagged.
    $response->assertSee('fk_child');
    $response->assertSee('fk_parent_id');
});

it('does not flag an indexed foreign-key column on SQLite', function (): void {
    // Add an index on the FK column: it must no longer be flagged.
    Schema::connection('readonly')->dropIfExists('fk_child');
    Schema::connection('readonly')->create('fk_child', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('fk_parent_id')->constrained('fk_parent');
        $table->string('label');
        // An explicit index leading on the FK column closes the gap.
        $table->index('fk_parent_id');
    });

    // The child table's FK column is now indexed. The tool only emits a column
    // name when it flags that column as missing an index, so an indexed FK column
    // is absent from the output entirely.
    DbMissingFkIndexesStubServer::tool(DbMissingFkIndexesTool::class, [])
        ->assertOk()
        ->assertDontSee('fk_parent_id');
});

// --- unknown table: clean error ---

it('returns a clean error for an unknown table without leaking driver exceptions', function (): void {
    DbMissingFkIndexesStubServer::tool(DbMissingFkIndexesTool::class, ['table' => 'nonexistent_table'])
        ->assertHasErrors();
});

// --- binding safety: a crafted table name cannot alter query structure ---

it('treats a crafted table name as a bound literal, never query structure', function (): void {
    $crafted = 'fk_child"; DROP TABLE fk_child; --';

    DbMissingFkIndexesStubServer::tool(DbMissingFkIndexesTool::class, ['table' => $crafted])
        ->assertHasErrors();

    expect(Schema::connection('readonly')->hasTable('fk_child'))->toBeTrue();
});

// --- PG/MySQL branch coverage via an injected fake CatalogQuery ---
//
// The suite runs on SQLite, so the pgsql/mysql anti-join branches never execute
// against a real server. The fake reports the engine + scope fragment and returns
// a canned catalog row carrying each branch's column aliases; we assert the tool
// maps the row into the missing-index report (definitive detection label).

it('maps the PostgreSQL pg_constraint anti-join row into a missing-index report', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('pgsql');
    $fake->shouldReceive('postgresSchemaScope')->andReturn("n.nspname NOT IN ('pg_catalog', 'information_schema')");
    $fake->shouldReceive('select')->andReturn([
        (object) [
            'schema' => 'public',
            'table' => 'order_items',
            'constraint' => 'order_items_order_id_fkey',
            'columns' => 'order_id',
        ],
    ]);
    app()->instance(CatalogQuery::class, $fake);

    $response = DbMissingFkIndexesStubServer::tool(DbMissingFkIndexesTool::class, [])
        ->assertOk();

    $response->assertSee('pgsql');
    $response->assertSee('definitive');
    $response->assertSee('missing_indexes');
    $response->assertSee('order_items');
    $response->assertSee('order_items_order_id_fkey');
});

it('maps the MySQL KEY_COLUMN_USAGE row into a missing-index report', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('mysql');
    $fake->shouldReceive('mysqlDatabaseScope')->andReturn('kcu.TABLE_SCHEMA = DATABASE()');
    $fake->shouldReceive('select')->andReturn([
        (object) [
            'table' => 'line_items',
            'column' => 'invoice_id',
            'references_table' => 'invoices',
            'references_column' => 'id',
            'constraint' => 'line_items_invoice_id_foreign',
        ],
    ]);
    app()->instance(CatalogQuery::class, $fake);

    $response = DbMissingFkIndexesStubServer::tool(DbMissingFkIndexesTool::class, [])
        ->assertOk();

    $response->assertSee('mysql');
    $response->assertSee('definitive');
    $response->assertSee('line_items');
    $response->assertSee('invoice_id');
    $response->assertSee('references_table');
});

// --- unsupported engine degrades to available:false ---

it('returns available:false for an engine it does not understand', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('sqlsrv');
    app()->instance(CatalogQuery::class, $fake);

    DbMissingFkIndexesStubServer::tool(DbMissingFkIndexesTool::class, [])
        ->assertOk()
        ->assertSee('available');
});

afterEach(function (): void {
    Mockery::close();
});
