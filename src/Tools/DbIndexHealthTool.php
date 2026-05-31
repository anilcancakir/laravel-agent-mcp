<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Anilcancakir\LaravelAgentMcp\Auditing\AuditLogger;
use Anilcancakir\LaravelAgentMcp\Database\CatalogQuery;
use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Anilcancakir\LaravelAgentMcp\Support\OutputRedactor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool: db_index_health
 *
 * Read-only index inventory and health advisories over the hardened readonly
 * connection, composing CatalogQuery (the package's catalog-SQL boundary).
 *
 * The base output is the index list for every table (or a single table when the
 * caller supplies a validated `table` argument). On top of that base, each engine
 * adds the health signals it can express:
 *   - PostgreSQL: unused indexes (idx_scan = 0 in pg_stat_user_indexes), excluding
 *     UNIQUE / constraint-backing / partial / partition-child indexes so the
 *     advisory does not flag indexes that are not safe to drop, plus the
 *     stats-reset timestamp and a sequential-scan advisory from pg_stat_user_tables.
 *   - MySQL: the index columns from information_schema.STATISTICS scoped to
 *     DATABASE() (no cross-database enumeration).
 *   - SQLite: pragma_index_list / pragma_index_info per table.
 *
 * Every catalog SELECT binds the table name as a literal value (never interpolated)
 * and a caller-supplied table is validated against knownTables() first, so an
 * unknown or crafted name is rejected cleanly before any query runs. An engine the
 * tool does not understand yields a structured {available:false} payload rather than
 * an exception.
 */
#[Name('db_index_health')]
class DbIndexHealthTool extends AbstractAgentTool
{
    public function __construct(
        ReadonlyConnectionResolver $connectionResolver,
        OutputRedactor $outputRedactor,
        AuditLogger $auditLogger,
        protected readonly CatalogQuery $catalog,
    ) {
        parent::__construct($connectionResolver, $outputRedactor, $auditLogger);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->nullable()
                ->description('Optional table name. Omit to inspect every table; provide to scope the report to one table.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit the invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. Resolve and validate the optional table scope before any query runs.
        $table = $request->get('table');
        $table = ($table === null || $table === '') ? null : (string) $table;

        if ($table !== null && ! in_array($table, $this->catalog->knownTables(), true)) {
            return Response::error("Unknown table: {$table}");
        }

        // 4. Engine-branch on the readonly connection driver.
        $payload = match ($this->catalog->driver()) {
            'sqlite' => $this->sqliteReport($table),
            'pgsql' => $this->postgresReport($table),
            'mysql' => $this->mysqlReport($table),
            default => ['available' => false, 'reason' => 'unsupported database engine'],
        };

        return $this->respond($payload);
    }

    /**
     * SQLite index report: pragma_index_list per table, expanded with the indexed
     * columns from pragma_index_info. SQLite has no usage statistics, so the report
     * is the index inventory plus the origin (unique / pk / regular) per index.
     *
     * @return array<string, mixed>
     */
    private function sqliteReport(?string $table): array
    {
        $tables = $table !== null ? [$table] : $this->catalog->knownTables();

        $report = [];

        foreach ($tables as $name) {
            // pragma_index_list(?) returns one row per index on the table; the table
            // name is bound, never interpolated.
            $indexes = $this->catalog->select(
                'SELECT name, "unique", origin, partial FROM pragma_index_list(?)',
                [$name],
            );

            $report[$name] = array_map(function (object $index): array {
                // pragma_index_info(?) lists the columns of a single index; the index
                // name is bound.
                $columns = $this->catalog->select(
                    'SELECT name FROM pragma_index_info(?)',
                    [$index->name],
                );

                return [
                    'name' => $index->name,
                    'unique' => (bool) $index->unique,
                    'origin' => $index->origin,
                    'partial' => (bool) $index->partial,
                    'columns' => array_map(static fn (object $c): mixed => $c->name, $columns),
                ];
            }, $indexes);
        }

        return [
            'driver' => 'sqlite',
            'tables' => $report,
            'note' => 'SQLite exposes no index-usage statistics; the report is the index inventory only.',
        ];
    }

    /**
     * PostgreSQL index report: the index inventory plus an unused-index advisory.
     *
     * The unused-index query reads idx_scan from pg_stat_user_indexes and keeps only
     * indexes that have never been scanned (idx_scan = 0). It deliberately excludes
     * UNIQUE indexes, constraint-backing indexes (pg_constraint), partial indexes
     * (indpred IS NOT NULL) and partition-child indexes (pg_inherits) because those
     * are not safe to recommend dropping. The query is scoped to the current database
     * and excludes the system schemas, so it never enumerates across databases.
     *
     * @return array<string, mixed>
     */
    private function postgresReport(?string $table): array
    {
        $schemaScope = $this->catalog->postgresSchemaScope('n.nspname');

        // Bindings: the optional table filter is applied with a coalesced placeholder
        // so the SAME parameterised SQL covers "all tables" and "one table"; the table
        // name is always a bound literal, never interpolated.
        $bindings = [$table, $table];

        $unused = $this->catalog->select(
            <<<SQL
            SELECT
                n.nspname AS schema,
                t.relname AS table,
                i.relname AS index,
                s.idx_scan AS scans
            FROM pg_stat_user_indexes s
            JOIN pg_class i ON i.oid = s.indexrelid
            JOIN pg_class t ON t.oid = s.relid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            JOIN pg_index ix ON ix.indexrelid = s.indexrelid
            WHERE s.idx_scan = 0
              AND ix.indisunique = false
              AND ix.indpred IS NULL
              AND {$schemaScope}
              AND NOT EXISTS (
                  SELECT 1 FROM pg_constraint c WHERE c.conindid = s.indexrelid
              )
              AND NOT EXISTS (
                  SELECT 1 FROM pg_inherits h WHERE h.inhrelid = i.oid
              )
              AND (?::text IS NULL OR t.relname = ?)
            ORDER BY t.relname, i.relname
            SQL,
            $bindings,
        );

        $seqScans = $this->catalog->select(
            <<<'SQL'
            SELECT
                relname AS table,
                seq_scan AS sequential_scans,
                idx_scan AS index_scans
            FROM pg_stat_user_tables
            WHERE (?::text IS NULL OR relname = ?)
            ORDER BY seq_scan DESC
            SQL,
            $bindings,
        );

        $statsReset = $this->catalog->select(
            'SELECT stats_reset FROM pg_stat_database WHERE datname = current_database()',
        );

        return [
            'driver' => 'pgsql',
            'unused_indexes' => $unused,
            'sequential_scan_advisory' => $seqScans,
            'stats_reset' => $statsReset[0]->stats_reset ?? null,
            'note' => 'Unused = never scanned since stats_reset; UNIQUE/constraint/partial/partition indexes are excluded.',
        ];
    }

    /**
     * MySQL index report: index columns from information_schema.STATISTICS scoped to
     * the current database via DATABASE() (no cross-database enumeration). MySQL does
     * not expose per-index usage in core information_schema, so the report is the
     * index inventory with cardinality, not a usage advisory.
     *
     * @return array<string, mixed>
     */
    private function mysqlReport(?string $table): array
    {
        $databaseScope = $this->catalog->mysqlDatabaseScope('TABLE_SCHEMA');

        $rows = $this->catalog->select(
            <<<SQL
            SELECT
                TABLE_NAME AS `table`,
                INDEX_NAME AS `index`,
                COLUMN_NAME AS `column`,
                SEQ_IN_INDEX AS seq_in_index,
                NON_UNIQUE AS non_unique,
                CARDINALITY AS cardinality
            FROM information_schema.STATISTICS
            WHERE {$databaseScope}
              AND (? IS NULL OR TABLE_NAME = ?)
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
            SQL,
            [$table, $table],
        );

        return [
            'driver' => 'mysql',
            'indexes' => $rows,
            'note' => 'MySQL core information_schema exposes index inventory + cardinality, not per-index usage counts.',
        ];
    }

    /**
     * Redact and JSON-encode the report payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload): Response
    {
        $redacted = $this->redactor()->redactArray($payload);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
