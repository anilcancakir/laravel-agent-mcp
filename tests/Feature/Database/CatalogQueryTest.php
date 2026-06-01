<?php

use Anilcancakir\LaravelAgentMcp\Database\CatalogQuery;
use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CatalogQuery is the read-only catalog-SQL boundary the DB-health tools compose.
// These tests pin the security-critical contract: catalog SELECTs
// run through the hardened readonly connection with bindings only, never string
// interpolation, so a crafted table name passed as a binding is a literal value
// and can never alter the query structure.

beforeEach(function (): void {
    // The package reads the readonly connection name from config; point it at the
    // test readonly SQLite connection (mirrors DbSchemaToolTest).
    config()->set('agent-mcp.connection', 'readonly');

    // Build the fixture schema on the readonly connection BEFORE CatalogQuery
    // hardens it (ReadonlyConnectionResolver sets PRAGMA query_only = ON on first
    // resolve), otherwise the CREATE is refused.
    Schema::connection('readonly')->dropIfExists('catalog_fixtures');
    Schema::connection('readonly')->create('catalog_fixtures', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

function makeCatalogQuery(): CatalogQuery
{
    return new CatalogQuery(new ReadonlyConnectionResolver);
}

// --- driver() reports the engine ---

it('reports the readonly connection driver name', function (): void {
    expect(makeCatalogQuery()->driver())->toBe('sqlite');
});

// --- knownTables() lists the readonly connection tables ---

it('lists the known tables on the readonly connection', function (): void {
    expect(makeCatalogQuery()->knownTables())->toContain('catalog_fixtures');
});

// --- select() runs a bound catalog query ---

it('runs a bound catalog SELECT on the readonly connection', function (): void {
    $rows = makeCatalogQuery()->select(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
        ['catalog_fixtures'],
    );

    expect($rows)->toHaveCount(1);
    expect($rows[0]->name)->toBe('catalog_fixtures');
});

// --- binding safety: a crafted table name cannot alter query structure ---

it('treats a crafted table name binding as a literal value, never query structure', function (): void {
    // A name carrying SQL metacharacters and a stacked-statement attempt. Passed as a
    // PDO binding it is a literal string compared against the catalog, so it matches no
    // table, returns zero rows, and does NOT execute the injected statement or error.
    $crafted = 'catalog_fixtures"; DROP TABLE catalog_fixtures; --';

    $rows = makeCatalogQuery()->select(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
        [$crafted],
    );

    expect($rows)->toBe([]);

    // The injected DROP must NOT have run: the fixture table is still present.
    expect(makeCatalogQuery()->knownTables())->toContain('catalog_fixtures');
});

// --- engine-scoping helpers document the single-DB rule ---

it('exposes a PostgreSQL system-schema exclusion scope fragment', function (): void {
    $scope = makeCatalogQuery()->postgresSchemaScope('n.nspname');

    expect($scope)->toContain('pg_catalog');
    expect($scope)->toContain('information_schema');
});

it('exposes a MySQL current-database scope fragment', function (): void {
    expect(makeCatalogQuery()->mysqlDatabaseScope('TABLE_SCHEMA'))
        ->toContain('DATABASE()');
});
