<?php

namespace Anilcancakir\LaravelAgentMcp\Database;

use Illuminate\Database\Connection;

/**
 * Shared read-only catalog-SQL boundary for the DB-health tools.
 *
 * The DB-health tools (db_index_health, db_missing_fk_indexes, db_table_sizes,
 * db_slow_queries, db_active_locks, migrations_status) all need the same three
 * things: the engine name to branch on, a way to run a PACKAGE-authored catalog
 * SELECT on the hardened readonly connection with bindings (never interpolation),
 * and the table list to validate a caller-supplied table argument before it is
 * ever bound. This helper centralizes that boundary so the per-engine dispatch and
 * single-database scoping live in one audited place rather than being re-derived in
 * six tools.
 *
 * Security model (Oracle findings 1 + 3):
 *   - Every catalog SELECT runs through the SAME hardened readonly connection the
 *     tools use for everything else (ReadonlyConnectionResolver::connection): the
 *     real SQL-injection boundary. The catalog SQL itself is package-authored, so it
 *     deliberately does NOT route through SelectStatementValidator (that allowlist
 *     grammar is for AGENT-supplied raw SQL only and would reject legitimate catalog
 *     functions such as pg_total_relation_size).
 *   - select() forwards bindings to the driver's prepared statement. The single
 *     variable a DB-health tool ever needs to inject is a table name, and it is
 *     always passed as a binding (a literal value), never concatenated into the SQL.
 *     A crafted table name is therefore compared as a string against the catalog and
 *     can never change the query structure.
 *   - Identifiers are never interpolated. knownTables() lets a tool reject an unknown
 *     table argument up front (defense-in-depth on top of binding, mirroring
 *     DbSchemaTool), so a typo or probe yields a clean denial, not a driver exception.
 *   - Single-database scoping: PostgreSQL catalog queries must filter to
 *     current_database() and exclude the pg_catalog / information_schema system
 *     schemas; MySQL catalog queries must carry TABLE_SCHEMA = DATABASE(). The
 *     per-engine scope fragments below encode that rule so no DB-health tool enumerates
 *     across databases on a shared cluster.
 */
class CatalogQuery
{
    public function __construct(
        protected readonly ReadonlyConnectionResolver $connectionResolver,
    ) {}

    /**
     * The engine name of the hardened readonly connection, for per-engine dispatch
     * in the DB-health tools (e.g. 'pgsql', 'mysql', 'sqlite').
     */
    public function driver(): string
    {
        return $this->readonly()->getDriverName();
    }

    /**
     * Run a package-authored catalog SELECT on the hardened readonly connection,
     * passing only bindings (never interpolating identifiers or values into the SQL).
     *
     * The caller owns the SQL string (it is package-authored, not agent-supplied);
     * the bindings carry the single dynamic value a catalog query ever needs, a table
     * name, as a prepared-statement parameter so it cannot alter the query structure.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return array<int, \stdClass>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->readonly()->select($sql, $bindings);
    }

    /**
     * The table names present on the readonly connection, so a DB-health tool can
     * validate a caller-supplied table argument before binding it (defense-in-depth
     * on top of binding, mirroring DbSchemaTool's known-table check).
     *
     * @return array<int, string>
     */
    public function knownTables(): array
    {
        return array_column($this->readonly()->getSchemaBuilder()->getTables(), 'name');
    }

    /**
     * PostgreSQL single-database scope fragment: a SQL predicate that excludes the
     * pg_catalog and information_schema system schemas from a catalog query keyed on
     * the given schema-name column. Combined with each query's own
     * current_database() filter, this keeps PG introspection inside the current
     * database and out of the server's system catalogs (Oracle finding 3: no
     * cross-database enumeration on a shared cluster).
     *
     * The column name is a package-authored identifier (e.g. 'n.nspname'), never
     * agent input, so embedding it in the fragment carries no injection surface.
     */
    public function postgresSchemaScope(string $schemaColumn): string
    {
        return "{$schemaColumn} NOT IN ('pg_catalog', 'information_schema')";
    }

    /**
     * MySQL single-database scope fragment: a SQL predicate binding a catalog query
     * to the current database via DATABASE() on the given schema column (e.g.
     * 'TABLE_SCHEMA'). Keeps information_schema lookups inside the connected database
     * rather than enumerating every schema on the server (Oracle finding 3).
     *
     * The column name is a package-authored identifier, never agent input.
     */
    public function mysqlDatabaseScope(string $schemaColumn): string
    {
        return "{$schemaColumn} = DATABASE()";
    }

    /**
     * The hardened read-only connection every catalog SELECT runs through: the
     * package's real SQL-injection boundary (ReadonlyConnectionResolver enforces
     * native prepares + per-engine read-only session hardening).
     */
    protected function readonly(): Connection
    {
        return $this->connectionResolver->connection();
    }
}
