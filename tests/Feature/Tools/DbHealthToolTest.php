<?php

use Anilcancakir\LaravelAgentMcp\Database\CatalogQuery;
use Anilcancakir\LaravelAgentMcp\Tools\DbHealthTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;
use Mockery\Expectation;

// A minimal server that hosts only DbHealthTool, keeping these tests isolated from AgentMcpServer.

/**
 * Inline stub server that hosts DbHealthTool for this test file only.
 */
final class DbHealthStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbHealthTool::class,
    ];
}

/**
 * Call the tool directly and decode its JSON text.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function dbHealthCall(array $arguments): array
{
    $response = app(DbHealthTool::class)->handle(new Request($arguments));

    expect($response->isError())->toBeFalse();

    return json_decode((string) $response->content(), associative: true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Bind a fake CatalogQuery reporting the given engine; select() returns what the callback maps from the SQL.
 */
function fakeDbHealthCatalog(string $driver, ?Closure $select = null): void
{
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn($driver);
    $fake->shouldReceive('postgresSchemaScope')->andReturn("n.nspname NOT IN ('pg_catalog', 'information_schema')");
    $fake->shouldReceive('mysqlDatabaseScope')->andReturn('TABLE_SCHEMA = DATABASE()');

    if ($select !== null) {
        /** @var Expectation $selectExpectation */
        $selectExpectation = $fake->shouldReceive('select');
        $selectExpectation->andReturnUsing($select);
    }

    app()->instance(CatalogQuery::class, $fake);
}

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.db_health', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build the fixtures on the readonly connection BEFORE the tool hardens it
    // (ReadonlyConnectionResolver sets PRAGMA query_only=ON on first access).
    Schema::connection('readonly')->dropIfExists('health_child');
    Schema::connection('readonly')->dropIfExists('health_parent');
    Schema::connection('readonly')->dropIfExists('health_posts');

    Schema::connection('readonly')->create('health_posts', function (Blueprint $table): void {
        $table->id();
        $table->string('title')->index();
        $table->string('slug')->unique();
        $table->timestamps();
    });

    Schema::connection('readonly')->create('health_parent', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    // A real foreign key with NO index on the referencing column: the gap missing_fk must flag.
    Schema::connection('readonly')->create('health_child', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('health_parent_id')->constrained('health_parent');
    });
});

// --- gate, annotations, section validation ---

it('denies the call when db_health is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_health', false);

    DbHealthStubServer::tool(DbHealthTool::class, [])->assertHasErrors();
    DbHealthStubServer::tool(DbHealthTool::class, ['section' => 'indexes'])->assertHasErrors();
});

it('is annotated read-only and named db_health', function (): void {
    $tool = app(DbHealthTool::class);

    expect($tool->name())->toBe('db_health')
        ->and($tool->toArray()['annotations'])->toHaveKey('readOnlyHint', true)
        ->and(mb_strlen($tool->description()))->toBeLessThanOrEqual(1500);
});

it('rejects an invalid section and names the valid ones', function (): void {
    DbHealthStubServer::tool(DbHealthTool::class, ['section' => 'bogus'])
        ->assertHasErrors(['The section must be one of: sizes, indexes, missing_fk.']);

    DbHealthStubServer::tool(DbHealthTool::class, ['section' => ''])
        ->assertHasErrors(['The section must be one of: sizes, indexes, missing_fk.']);
});

// --- SQLite, one section per case ---

it('defaults to the sizes section on SQLite', function (): void {
    DbHealthStubServer::tool(DbHealthTool::class, [])
        ->assertOk()
        ->assertSee('"engine":"sqlite"')
        ->assertSee('health_posts')
        ->assertSee('total_bytes')
        ->assertDontSee('"tables": ');
});

it('scopes the sizes section to one known table', function (): void {
    DbHealthStubServer::tool(DbHealthTool::class, ['section' => 'sizes', 'table' => 'health_posts'])
        ->assertOk()
        ->assertSee('health_posts')
        ->assertDontSee('health_parent');
});

it('reports the index inventory per table for the indexes section on SQLite', function (): void {
    $payload = dbHealthCall(['section' => 'indexes']);

    expect($payload['driver'])->toBe('sqlite')
        ->and($payload['tables'])->toHaveKeys(['health_posts', 'health_parent', 'health_child'])
        ->and(array_column($payload['tables']['health_posts'], 'unique'))->toContain(true)
        ->and($payload)->not->toHaveKey('truncated')
        ->and($payload)->not->toHaveKey('total');
});

it('scopes the indexes section to one known table', function (): void {
    $payload = dbHealthCall(['section' => 'indexes', 'table' => 'health_posts']);

    expect(array_keys($payload['tables']))->toBe(['health_posts']);
});

it('flags an unindexed foreign-key column for the missing_fk section on SQLite', function (): void {
    DbHealthStubServer::tool(DbHealthTool::class, ['section' => 'missing_fk'])
        ->assertOk()
        ->assertSee('"detection":"heuristic"')
        ->assertSee('health_child')
        ->assertSee('health_parent_id');
});

it('does not flag an indexed foreign-key column for the missing_fk section', function (): void {
    Schema::connection('readonly')->table('health_child', function (Blueprint $table): void {
        $table->index('health_parent_id');
    });

    DbHealthStubServer::tool(DbHealthTool::class, ['section' => 'missing_fk'])
        ->assertOk()
        ->assertDontSee('health_parent_id');
});

// --- table guard ---

it('rejects an unknown table in every section', function (string $section): void {
    DbHealthStubServer::tool(DbHealthTool::class, ['section' => $section, 'table' => 'nonexistent_table'])
        ->assertHasErrors(['Unknown table: nonexistent_table']);
})->with(['sizes', 'indexes', 'missing_fk']);

it('treats a crafted table name as a rejected literal, never query structure', function (string $section): void {
    $crafted = 'health_posts"; DROP TABLE health_posts; --';

    DbHealthStubServer::tool(DbHealthTool::class, ['section' => $section, 'table' => $crafted])
        ->assertHasErrors();

    expect(Schema::connection('readonly')->hasTable('health_posts'))->toBeTrue();
})->with(['sizes', 'indexes', 'missing_fk']);

// --- limit, total and truncated ---

it('caps the tables of a section at the default limit and reports total and truncated', function (): void {
    Schema::connection('readonly')->dropAllTables();

    foreach (range(1, 60) as $number) {
        Schema::connection('readonly')->create(sprintf('health_cap_%02d', $number), function (Blueprint $table): void {
            $table->id();
        });
    }

    $payload = dbHealthCall(['section' => 'indexes']);

    expect($payload['tables'])->toHaveCount(50)
        ->and($payload['total'])->toBe(60)
        ->and($payload['truncated'])->toBeTrue();
});

it('honours an explicit limit and clamps it into range', function (): void {
    $limited = dbHealthCall(['section' => 'missing_fk', 'limit' => 2]);
    $indexes = dbHealthCall(['section' => 'indexes', 'limit' => 2]);
    $clamped = dbHealthCall(['section' => 'indexes', 'limit' => 0]);

    expect($indexes['tables'])->toHaveCount(2)
        ->and($indexes['truncated'])->toBeTrue()
        ->and($clamped['tables'])->toHaveCount(1)
        ->and($limited['total'])->toBe(3)
        ->and($limited['truncated'])->toBeTrue();
});

it('does not mark the output truncated when every table fits', function (): void {
    $payload = dbHealthCall(['section' => 'indexes', 'limit' => 200]);

    expect($payload)->not->toHaveKey('truncated')
        ->and($payload)->not->toHaveKey('total');
});

// --- PostgreSQL via an injected fake CatalogQuery (the suite runs on SQLite) ---

it('maps pg_stat_user_tables rows into the sizes section on PostgreSQL', function (): void {
    fakeDbHealthCatalog('pgsql', fn (): array => [
        (object) [
            'table_name' => 'public.orders',
            'total_bytes' => 1048576,
            'table_bytes' => 786432,
            'index_bytes' => 262144,
            'live_rows' => 900,
            'dead_rows' => 100,
        ],
    ]);

    $payload = dbHealthCall(['section' => 'sizes']);

    expect($payload['engine'])->toBe('pgsql')
        ->and($payload['tables'][0])->toBe([
            'table' => 'public.orders',
            'total_bytes' => 1048576,
            'table_bytes' => 786432,
            'index_bytes' => 262144,
            'live_rows' => 900,
            'dead_rows' => 100,
            'dead_pct' => 10,
        ]);
});

it('maps the unused-index and seq-scan catalog rows into the indexes section on PostgreSQL', function (): void {
    fakeDbHealthCatalog('pgsql', function (string $sql): array {
        // Regression guard (real-PG bug, SQLSTATE 42P18): an optional-table filter written as a
        // bare "? IS NULL" leaves the placeholder type indeterminate with native prepares.
        if (str_contains($sql, 'IS NULL')) {
            expect($sql)->toContain('?::text IS NULL')
                ->and($sql)->not->toContain('(? IS NULL');
        }

        if (str_contains($sql, 'pg_stat_user_indexes')) {
            return [(object) ['schema' => 'public', 'table' => 'orders', 'index' => 'orders_legacy_idx', 'scans' => 0]];
        }

        if (str_contains($sql, 'pg_stat_user_tables')) {
            return [(object) ['table' => 'orders', 'sequential_scans' => 1200, 'index_scans' => 30]];
        }

        return [(object) ['stats_reset' => '2026-05-01 00:00:00+00']];
    });

    $payload = dbHealthCall(['section' => 'indexes']);

    expect($payload['driver'])->toBe('pgsql')
        ->and($payload['unused_indexes'][0]['index'])->toBe('orders_legacy_idx')
        ->and($payload['sequential_scan_advisory'][0]['sequential_scans'])->toBe(1200)
        ->and($payload['stats_reset'])->toBe('2026-05-01 00:00:00+00');
});

it('maps the pg_constraint anti-join rows into the missing_fk section on PostgreSQL', function (): void {
    fakeDbHealthCatalog('pgsql', function (string $sql): array {
        if (str_contains($sql, 'IS NULL')) {
            expect($sql)->toContain('?::text IS NULL')
                ->and($sql)->not->toContain('(? IS NULL');
        }

        return [
            (object) [
                'schema' => 'public',
                'table' => 'order_items',
                'constraint' => 'order_items_order_id_fkey',
                'columns' => 'order_id',
            ],
        ];
    });

    $payload = dbHealthCall(['section' => 'missing_fk']);

    expect($payload['driver'])->toBe('pgsql')
        ->and($payload['detection'])->toBe('definitive')
        ->and($payload['missing_indexes'][0]['constraint'])->toBe('order_items_order_id_fkey');
});

it('caps catalog rows by distinct table on PostgreSQL and reports total and truncated', function (): void {
    fakeDbHealthCatalog('pgsql', fn (): array => [
        (object) ['table_name' => 'public.a', 'total_bytes' => 3, 'table_bytes' => 3, 'index_bytes' => 0, 'live_rows' => 0, 'dead_rows' => 0],
        (object) ['table_name' => 'public.b', 'total_bytes' => 2, 'table_bytes' => 2, 'index_bytes' => 0, 'live_rows' => 0, 'dead_rows' => 0],
        (object) ['table_name' => 'public.c', 'total_bytes' => 1, 'table_bytes' => 1, 'index_bytes' => 0, 'live_rows' => 0, 'dead_rows' => 0],
    ]);

    $payload = dbHealthCall(['section' => 'sizes', 'limit' => 2]);

    expect(array_column($payload['tables'], 'table'))->toBe(['public.a', 'public.b'])
        ->and($payload['total'])->toBe(3)
        ->and($payload['truncated'])->toBeTrue();
});

// --- MySQL via an injected fake CatalogQuery ---

it('maps information_schema.TABLES rows into the sizes section on MySQL', function (): void {
    fakeDbHealthCatalog('mysql', fn (): array => [
        (object) [
            'table_name' => 'invoices',
            'data_bytes' => 524288,
            'index_bytes' => 131072,
            'total_bytes' => 655360,
            'estimated_rows' => 4200,
            'free_bytes' => 8192,
        ],
    ]);

    $payload = dbHealthCall(['section' => 'sizes']);

    expect($payload['engine'])->toBe('mysql')
        ->and($payload['source'])->toContain('information_schema.TABLES')
        ->and($payload['tables'][0]['estimated_rows'])->toBe(4200);
});

it('maps the STATISTICS rows into the indexes section on MySQL', function (): void {
    fakeDbHealthCatalog('mysql', fn (): array => [
        (object) [
            'table' => 'invoices',
            'index' => 'invoices_customer_id_index',
            'column' => 'customer_id',
            'seq_in_index' => 1,
            'non_unique' => 1,
            'cardinality' => 5000,
        ],
    ]);

    $payload = dbHealthCall(['section' => 'indexes']);

    expect($payload['driver'])->toBe('mysql')
        ->and($payload['indexes'][0]['index'])->toBe('invoices_customer_id_index')
        ->and($payload['indexes'][0]['cardinality'])->toBe(5000);
});

it('maps the KEY_COLUMN_USAGE rows into the missing_fk section on MySQL', function (): void {
    fakeDbHealthCatalog('mysql', fn (): array => [
        (object) [
            'table' => 'line_items',
            'column' => 'invoice_id',
            'references_table' => 'invoices',
            'references_column' => 'id',
            'constraint' => 'line_items_invoice_id_foreign',
        ],
    ]);

    $payload = dbHealthCall(['section' => 'missing_fk']);

    expect($payload['driver'])->toBe('mysql')
        ->and($payload['detection'])->toBe('definitive')
        ->and($payload['missing_indexes'][0]['references_table'])->toBe('invoices');
});

// --- engines and degrade paths ---

it('returns available:false for an engine it does not understand', function (string $section): void {
    fakeDbHealthCatalog('sqlsrv');

    $payload = dbHealthCall(['section' => $section]);

    expect($payload['available'])->toBeFalse()
        ->and($payload['reason'])->toBe('unsupported database engine');
})->with(['sizes', 'indexes', 'missing_fk']);

it('degrades to the whole-file size when dbstat is missing and ignores the table argument', function (): void {
    $fake = Mockery::mock(CatalogQuery::class);
    $fake->shouldReceive('driver')->andReturn('sqlite');
    $fake->shouldReceive('knownTables')->andReturn(['health_posts']);
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

    $payload = dbHealthCall(['section' => 'sizes', 'table' => 'health_posts']);

    expect($payload['database_total_bytes'])->toBe(163840)
        ->and($payload)->not->toHaveKey('tables');
});

afterEach(function (): void {
    Mockery::close();
});
