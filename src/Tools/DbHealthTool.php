<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Anilcancakir\LaravelAgentMcp\Auditing\AuditLogger;
use Anilcancakir\LaravelAgentMcp\Database\CatalogQuery;
use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Anilcancakir\LaravelAgentMcp\Support\OutputRedactor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

/**
 * MCP tool: db_health
 *
 * One read-only database health surface over the hardened readonly connection, composing CatalogQuery
 * (the package's catalog-SQL boundary). The `section` argument picks the report; each engine expresses
 * it with the strongest signal it has and an engine the tool does not understand degrades to a
 * structured {available:false} payload instead of an exception.
 *
 * A caller-supplied table is validated against knownTables() once, before any query runs, and is only
 * ever bound as a literal, so an unknown or crafted name is rejected cleanly. `limit` bounds the number
 * of tables a section reports; the output carries `total` and `truncated` only when something was cut.
 */
#[Name('db_health')]
#[Description(<<<'TEXT'
    Database health report: table sizes and dead tuples (sizes), index inventory and unused indexes (indexes), foreign keys without a covering index (missing_fk). Use it when investigating disk usage, bloat, slow joins or index usage.

    - `section` picks the report (default sizes); `table` scopes it to one table.
    - `limit` bounds the number of tables (default 50, at most 200); `total` and `truncated` appear only when tables were cut.
    - PostgreSQL adds dead-tuple percentages, unused-index detection and a sequential-scan advisory; MySQL sizes are storage-engine estimates; SQLite sizes need the dbstat table and degrade to the whole-file size, where `table` is ignored.
    - missing_fk is definitive on PostgreSQL and MySQL and a labelled heuristic on SQLite.
    TEXT)]
#[IsReadOnly]
class DbHealthTool extends AbstractAgentTool
{
    private const SECTIONS = ['sizes', 'indexes', 'missing_fk'];

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

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
            'section' => $schema->string()
                ->enum(self::SECTIONS)
                ->description('Report to run. Defaults to sizes.'),

            'table' => $schema->string()
                ->nullable()
                ->description('Optional table name. Omit to cover every table.'),

            'limit' => $schema->integer()
                ->nullable()
                ->description('Max tables to report (default 50, at most 200).'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Resolve the section, then audit the invocation shape (keys + types, never values).
        $section = $this->requestedSection($request, self::SECTIONS);

        $this->audit($this->argumentShape($request->all()), $section);

        // 3. Validate the optional table scope before any query runs.
        $table = $request->get('table');
        $table = ($table === null || $table === '') ? null : (string) $table;

        if ($table !== null && ! in_array($table, $this->catalog->knownTables(), true)) {
            return Response::error("Unknown table: {$table}");
        }

        // 4. Run the section on the engine of the readonly connection.
        $limit = $this->resolveLimit($request->get('limit'));

        // requestedSection() already rejected any other name, so sizes (the default section) is the fallback arm.
        $payload = match ($section) {
            'indexes' => $this->indexes($table, $limit),
            'missing_fk' => $this->missingFk($table, $limit),
            default => $this->sizes($table, $limit),
        };

        return $this->jsonResponse($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function sizes(?string $table, int $limit): array
    {
        return match ($this->catalog->driver()) {
            'pgsql' => $this->sizesPostgres($table, $limit),
            'mysql' => $this->sizesMysql($table, $limit),
            'sqlite' => $this->sizesSqlite($table, $limit),
            default => $this->unsupportedEngine(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function indexes(?string $table, int $limit): array
    {
        return match ($this->catalog->driver()) {
            'sqlite' => $this->indexesSqlite($table, $limit),
            'pgsql' => $this->indexesPostgres($table, $limit),
            'mysql' => $this->indexesMysql($table, $limit),
            default => $this->unsupportedEngine(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function missingFk(?string $table, int $limit): array
    {
        return match ($this->catalog->driver()) {
            'sqlite' => $this->missingFkSqlite($table, $limit),
            'pgsql' => $this->missingFkPostgres($table, $limit),
            'mysql' => $this->missingFkMysql($table, $limit),
            default => $this->unsupportedEngine(),
        };
    }

    /**
     * PostgreSQL: byte sizes via the relation-size functions plus a dead-tuple percentage from the
     * statistics collector, scoped to user schemas in the current database.
     *
     * @return array<string, mixed>
     */
    private function sizesPostgres(?string $table, int $limit): array
    {
        $schemaScope = $this->catalog->postgresSchemaScope('schemaname');

        $sql = 'SELECT '
            ."schemaname || '.' || relname AS table_name, "
            .'pg_total_relation_size(relid) AS total_bytes, '
            .'pg_relation_size(relid) AS table_bytes, '
            .'pg_indexes_size(relid) AS index_bytes, '
            .'n_live_tup AS live_rows, '
            .'n_dead_tup AS dead_rows '
            .'FROM pg_stat_user_tables '
            ."WHERE {$schemaScope}";

        $bindings = [];

        if ($table !== null) {
            $sql .= ' AND relname = ?';
            $bindings[] = $table;
        }

        $sql .= ' ORDER BY total_bytes DESC';

        $rows = array_map(function (object $row): array {
            $live = (int) $row->live_rows;
            $dead = (int) $row->dead_rows;
            $totalTup = $live + $dead;

            return [
                'table' => $row->table_name,
                'total_bytes' => (int) $row->total_bytes,
                'table_bytes' => (int) $row->table_bytes,
                'index_bytes' => (int) $row->index_bytes,
                'live_rows' => $live,
                'dead_rows' => $dead,
                'dead_pct' => $totalTup > 0 ? round($dead / $totalTup * 100, 2) : 0.0,
            ];
        }, $this->catalog->select($sql, $bindings));

        [$tables, $total] = $this->capByTable($rows, $limit);

        return [
            'engine' => 'pgsql',
            'source' => 'pg_total_relation_size + pg_stat_user_tables',
            'tables' => $tables,
            ...$this->cutMarker($total, $limit),
        ];
    }

    /**
     * MySQL: storage-engine size estimates from information_schema.TABLES, scoped to the connected
     * database via DATABASE().
     *
     * @return array<string, mixed>
     */
    private function sizesMysql(?string $table, int $limit): array
    {
        $databaseScope = $this->catalog->mysqlDatabaseScope('TABLE_SCHEMA');

        $sql = 'SELECT '
            .'TABLE_NAME AS table_name, '
            .'DATA_LENGTH AS data_bytes, '
            .'INDEX_LENGTH AS index_bytes, '
            .'(DATA_LENGTH + INDEX_LENGTH) AS total_bytes, '
            .'TABLE_ROWS AS estimated_rows, '
            .'DATA_FREE AS free_bytes '
            .'FROM information_schema.TABLES '
            ."WHERE {$databaseScope} AND TABLE_TYPE = 'BASE TABLE'";

        $bindings = [];

        if ($table !== null) {
            $sql .= ' AND TABLE_NAME = ?';
            $bindings[] = $table;
        }

        $sql .= ' ORDER BY total_bytes DESC';

        $rows = array_map(fn (object $row): array => [
            'table' => $row->table_name,
            'total_bytes' => (int) $row->total_bytes,
            'data_bytes' => (int) $row->data_bytes,
            'index_bytes' => (int) $row->index_bytes,
            'estimated_rows' => (int) $row->estimated_rows,
            'free_bytes' => (int) $row->free_bytes,
        ], $this->catalog->select($sql, $bindings));

        [$tables, $total] = $this->capByTable($rows, $limit);

        return [
            'engine' => 'mysql',
            'source' => 'information_schema.TABLES (storage-engine estimates)',
            'note' => 'TABLE_ROWS and length columns are InnoDB estimates, not exact counts.',
            'tables' => $tables,
            ...$this->cutMarker($total, $limit),
        ];
    }

    /**
     * SQLite: exact per-table bytes from the dbstat virtual table when the build has it, otherwise the
     * whole-file size. `table` stays ignored on the whole-file path because page_count * page_size
     * cannot be attributed to a single table.
     *
     * @return array<string, mixed>
     */
    private function sizesSqlite(?string $table, int $limit): array
    {
        if (! $this->sqliteHasDbstat()) {
            return $this->sqlitePageCountDegrade();
        }

        $sql = 'SELECT name AS table_name, SUM(pgsize) AS total_bytes FROM dbstat';
        $bindings = [];

        if ($table !== null) {
            $sql .= ' WHERE name = ?';
            $bindings[] = $table;
        }

        $sql .= ' GROUP BY name ORDER BY total_bytes DESC';

        $rows = array_map(fn (object $row): array => [
            'table' => $row->table_name,
            'total_bytes' => (int) $row->total_bytes,
        ], $this->catalog->select($sql, $bindings));

        [$tables, $total] = $this->capByTable($rows, $limit);

        return [
            'engine' => 'sqlite',
            'source' => 'dbstat',
            'tables' => $tables,
            ...$this->cutMarker($total, $limit),
        ];
    }

    /**
     * The whole-file byte size only, for builds without dbstat.
     *
     * @return array<string, mixed>
     */
    private function sqlitePageCountDegrade(): array
    {
        $pageCount = (int) ($this->catalog->select('PRAGMA page_count')[0]->page_count ?? 0);
        $pageSize = (int) ($this->catalog->select('PRAGMA page_size')[0]->page_size ?? 0);

        return [
            'engine' => 'sqlite',
            'source' => 'page_count * page_size (dbstat not compiled in)',
            'note' => 'Per-table sizes are unavailable without the dbstat virtual table; only the whole-database file size is reported.',
            'database_total_bytes' => $pageCount * $pageSize,
        ];
    }

    /**
     * A failed probe means the build lacks SQLITE_ENABLE_DBSTAT_VTAB and the sizes degrade.
     */
    private function sqliteHasDbstat(): bool
    {
        try {
            $this->catalog->select('SELECT 1 FROM dbstat LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * SQLite: pragma_index_list per table, expanded with the indexed columns from pragma_index_info.
     * SQLite has no usage statistics, so the report is the inventory plus each index origin.
     *
     * @return array<string, mixed>
     */
    private function indexesSqlite(?string $table, int $limit): array
    {
        $scope = $this->sqliteScope($table);

        $report = [];

        foreach (array_slice($scope, 0, $limit) as $name) {
            // The table and index names are bound, never interpolated.
            $indexes = $this->catalog->select(
                'SELECT name, "unique", origin, partial FROM pragma_index_list(?)',
                [$name],
            );

            $report[$name] = array_map(function (object $index): array {
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
            ...$this->cutMarker(count($scope), $limit),
        ];
    }

    /**
     * PostgreSQL: an unused-index advisory (idx_scan = 0) plus a sequential-scan advisory. UNIQUE,
     * constraint-backing, partial and partition-child indexes are excluded because they are not safe to
     * recommend dropping. Scoped to the current database and the user schemas.
     *
     * @return array<string, mixed>
     */
    private function indexesPostgres(?string $table, int $limit): array
    {
        $schemaScope = $this->catalog->postgresSchemaScope('n.nspname');

        // One coalesced placeholder covers "all tables" and "one table"; the name is always bound.
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

        [$unusedIndexes, $unusedTotal] = $this->capByTable($this->rowsToArrays($unused), $limit);
        [$sequentialScans, $seqTotal] = $this->capByTable($this->rowsToArrays($seqScans), $limit);

        return [
            'driver' => 'pgsql',
            'unused_indexes' => $unusedIndexes,
            'sequential_scan_advisory' => $sequentialScans,
            'stats_reset' => $statsReset[0]->stats_reset ?? null,
            'note' => 'Unused = never scanned since stats_reset; UNIQUE/constraint/partial/partition indexes are excluded.',
            ...$this->cutMarker(max($unusedTotal, $seqTotal), $limit),
        ];
    }

    /**
     * MySQL: index columns from information_schema.STATISTICS scoped to DATABASE(). Core
     * information_schema has no per-index usage, so the report is the inventory with cardinality.
     *
     * @return array<string, mixed>
     */
    private function indexesMysql(?string $table, int $limit): array
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

        [$indexes, $total] = $this->capByTable($this->rowsToArrays($rows), $limit);

        return [
            'driver' => 'mysql',
            'indexes' => $indexes,
            'note' => 'MySQL core information_schema exposes index inventory + cardinality, not per-index usage counts.',
            ...$this->cutMarker($total, $limit),
        ];
    }

    /**
     * SQLite heuristic: a referencing column that does not lead any index is flagged. A composite
     * foreign key is judged on its first referencing column, the one a single-column index would cover.
     *
     * @return array<string, mixed>
     */
    private function missingFkSqlite(?string $table, int $limit): array
    {
        $scope = $this->sqliteScope($table);

        $missing = [];

        foreach (array_slice($scope, 0, $limit) as $name) {
            $indexedLeadingColumns = $this->sqliteIndexedLeadingColumns($name);

            $foreignKeys = $this->catalog->select(
                'SELECT id, seq, "table" AS referenced_table, "from" AS referencing_column, "to" AS referenced_column FROM pragma_foreign_key_list(?)',
                [$name],
            );

            foreach ($foreignKeys as $fk) {
                // Only the leading referencing column (seq = 0) needs a covering index.
                if ((int) $fk->seq !== 0) {
                    continue;
                }

                if (in_array($fk->referencing_column, $indexedLeadingColumns, true)) {
                    continue;
                }

                $missing[] = [
                    'table' => $name,
                    'column' => $fk->referencing_column,
                    'references_table' => $fk->referenced_table,
                    'references_column' => $fk->referenced_column,
                ];
            }
        }

        return [
            'driver' => 'sqlite',
            'detection' => 'heuristic',
            'missing_indexes' => $missing,
            'note' => 'SQLite never auto-indexes foreign keys; a referencing column not leading any index is flagged (heuristic).',
            ...$this->cutMarker(count($scope), $limit),
        ];
    }

    /**
     * The columns that lead at least one index on the given SQLite table.
     *
     * @return array<int, string>
     */
    private function sqliteIndexedLeadingColumns(string $table): array
    {
        $indexes = $this->catalog->select(
            'SELECT name FROM pragma_index_list(?)',
            [$table],
        );

        $leading = [];

        foreach ($indexes as $index) {
            $columns = $this->catalog->select(
                'SELECT name FROM pragma_index_info(?) WHERE seqno = 0',
                [$index->name],
            );

            foreach ($columns as $column) {
                if (is_string($column->name)) {
                    $leading[] = $column->name;
                }
            }
        }

        return $leading;
    }

    /**
     * PostgreSQL definitive anti-join: a foreign-key constraint whose leading column set leads no index
     * on the child relation. Scoped to the current database and the user schemas.
     *
     * @return array<string, mixed>
     */
    private function missingFkPostgres(?string $table, int $limit): array
    {
        $schemaScope = $this->catalog->postgresSchemaScope('n.nspname');

        $rows = $this->catalog->select(
            <<<SQL
            SELECT
                n.nspname AS schema,
                t.relname AS table,
                c.conname AS constraint,
                array_to_string(ARRAY(
                    SELECT a.attname
                    FROM unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord)
                    JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
                    ORDER BY k.ord
                ), ',') AS columns
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE c.contype = 'f'
              AND {$schemaScope}
              AND (?::text IS NULL OR t.relname = ?)
              AND NOT EXISTS (
                  SELECT 1
                  FROM pg_index ix
                  WHERE ix.indrelid = c.conrelid
                    AND (ix.indkey::int2[])[0:array_length(c.conkey, 1) - 1] = c.conkey
              )
            ORDER BY t.relname, c.conname
            SQL,
            [$table, $table],
        );

        [$missing, $total] = $this->capByTable($this->rowsToArrays($rows), $limit);

        return [
            'driver' => 'pgsql',
            'detection' => 'definitive',
            'missing_indexes' => $missing,
            'note' => 'A foreign key whose leading column set matches no index leading columns is reported.',
            ...$this->cutMarker($total, $limit),
        ];
    }

    /**
     * MySQL: KEY_COLUMN_USAGE (the foreign-key columns) left-joined against STATISTICS at
     * SEQ_IN_INDEX = 1 (the leading index column), scoped to DATABASE().
     *
     * @return array<string, mixed>
     */
    private function missingFkMysql(?string $table, int $limit): array
    {
        $kcuScope = $this->catalog->mysqlDatabaseScope('kcu.TABLE_SCHEMA');

        $rows = $this->catalog->select(
            <<<SQL
            SELECT DISTINCT
                kcu.TABLE_NAME AS `table`,
                kcu.COLUMN_NAME AS `column`,
                kcu.REFERENCED_TABLE_NAME AS references_table,
                kcu.REFERENCED_COLUMN_NAME AS references_column,
                kcu.CONSTRAINT_NAME AS `constraint`
            FROM information_schema.KEY_COLUMN_USAGE kcu
            LEFT JOIN information_schema.STATISTICS s
              ON s.TABLE_SCHEMA = kcu.TABLE_SCHEMA
             AND s.TABLE_NAME = kcu.TABLE_NAME
             AND s.COLUMN_NAME = kcu.COLUMN_NAME
             AND s.SEQ_IN_INDEX = 1
            WHERE {$kcuScope}
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
              AND s.INDEX_NAME IS NULL
              AND (? IS NULL OR kcu.TABLE_NAME = ?)
            ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME
            SQL,
            [$table, $table],
        );

        [$missing, $total] = $this->capByTable($this->rowsToArrays($rows), $limit);

        return [
            'driver' => 'mysql',
            'detection' => 'definitive',
            'missing_indexes' => $missing,
            'note' => 'InnoDB auto-indexes foreign keys; a referencing column with no leading index column is reported.',
            ...$this->cutMarker($total, $limit),
        ];
    }

    /**
     * The SQLite tables a section walks: the validated one, or every known table in catalog order.
     *
     * @return array<int, string>
     */
    private function sqliteScope(?string $table): array
    {
        return $table !== null ? [$table] : $this->catalog->knownTables();
    }

    /**
     * Keep the rows of the first `$limit` distinct tables, so a table is never cut halfway through its
     * rows. Returns the kept rows and the number of distinct tables before the cut.
     *
     * @param  array<int, array<string, mixed>>  $rows  Each row carries its table name under `table`.
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function capByTable(array $rows, int $limit): array
    {
        $kept = [];
        $seen = [];

        foreach ($rows as $row) {
            $seen[$row['table']] = true;

            if (count($seen) <= $limit) {
                $kept[] = $row;
            }
        }

        return [$kept, count($seen)];
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsToArrays(array $rows): array
    {
        return array_map(static fn (object $row): array => (array) $row, $rows);
    }

    /**
     * The `total` and `truncated` keys, present only when the section reported fewer tables than exist.
     *
     * @return array<string, mixed>
     */
    private function cutMarker(int $total, int $limit): array
    {
        return $total > $limit ? ['total' => $total, 'truncated' => true] : [];
    }

    /**
     * Clamp the caller-supplied limit into 1..MAX_LIMIT, defaulting when it is absent or non-numeric.
     */
    private function resolveLimit(mixed $limit): int
    {
        if (! is_numeric($limit)) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min(self::MAX_LIMIT, (int) $limit));
    }

    /**
     * @return array<string, mixed>
     */
    private function unsupportedEngine(): array
    {
        return ['available' => false, 'reason' => 'unsupported database engine'];
    }
}
