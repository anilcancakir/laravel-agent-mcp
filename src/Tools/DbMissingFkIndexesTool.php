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
 * MCP tool: db_missing_fk_indexes
 *
 * Read-only detection of foreign-key columns that have no covering index, composing
 * CatalogQuery (the package's catalog-SQL boundary). An unindexed foreign key forces
 * a full scan of the child table on every parent delete/update and on every join, so
 * surfacing the gap is a common performance investigation.
 *
 * Each engine expresses the gap with the strongest signal it has:
 *   - PostgreSQL: a definitive pg_constraint + pg_index anti-join. A foreign-key
 *     constraint's leading column set is "covered" when an index leads with the same
 *     columns; constraints with no such index are reported. Scoped to the current
 *     database and the non-system schemas (no cross-database enumeration).
 *   - MySQL: information_schema.KEY_COLUMN_USAGE (the FK columns) left-joined against
 *     STATISTICS (the index leading columns), scoped to DATABASE(). InnoDB auto-creates
 *     an index for every foreign key, so a gap here is rare but still reported when present.
 *   - SQLite: a heuristic from pragma_foreign_key_list + pragma_index_list /
 *     pragma_index_info. SQLite never auto-indexes foreign keys, so a referencing
 *     column with no index leading on it is flagged. Labelled heuristic because SQLite
 *     exposes no constraint metadata beyond the pragma rows.
 *
 * The table name is always bound, never interpolated, and a caller-supplied table is
 * validated against knownTables() first. An engine the tool does not understand yields
 * a structured {available:false} payload.
 */
#[Name('db_missing_fk_indexes')]
class DbMissingFkIndexesTool extends AbstractAgentTool
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
                ->description('Optional table name. Omit to scan every table; provide to scope the report to one table.'),
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
     * SQLite heuristic: for each table, read its foreign keys (pragma_foreign_key_list)
     * and the leading column of every index (pragma_index_list + pragma_index_info). A
     * referencing column whose name is not the leading column of any index is flagged.
     *
     * Heuristic because SQLite never auto-indexes foreign keys and exposes no constraint
     * metadata beyond the pragma rows; a composite foreign key is evaluated on its first
     * referencing column, the column a single-column index would cover.
     *
     * @return array<string, mixed>
     */
    private function sqliteReport(?string $table): array
    {
        $tables = $table !== null ? [$table] : $this->catalog->knownTables();

        $missing = [];

        foreach ($tables as $name) {
            $indexedLeadingColumns = $this->sqliteIndexedLeadingColumns($name);

            // pragma_foreign_key_list(?) returns one row per referencing column of every
            // foreign key on the table; the table name is bound, never interpolated.
            $foreignKeys = $this->catalog->select(
                'SELECT id, seq, "table" AS referenced_table, "from" AS referencing_column, "to" AS referenced_column FROM pragma_foreign_key_list(?)',
                [$name],
            );

            foreach ($foreignKeys as $fk) {
                // Only the leading referencing column (seq = 0) needs a covering index
                // to satisfy the foreign key lookup; deeper composite columns ride it.
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
        ];
    }

    /**
     * The set of columns that lead at least one index on the given SQLite table.
     * A foreign-key column is considered covered when it leads some index.
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
            // The first column of the index (seqno = 0) is its leading column; that is
            // the column a foreign-key lookup can use. The index name is bound.
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
     * PostgreSQL definitive anti-join: a foreign-key constraint (pg_constraint contype
     * = 'f') whose leading column set is not the leading column set of any index on the
     * child relation is reported. Scoped to the current database via the namespace and
     * the non-system-schema scope fragment (no cross-database enumeration).
     *
     * @return array<string, mixed>
     */
    private function postgresReport(?string $table): array
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
              AND (? IS NULL OR t.relname = ?)
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

        return [
            'driver' => 'pgsql',
            'detection' => 'definitive',
            'missing_indexes' => $rows,
            'note' => 'A foreign key whose leading column set matches no index leading columns is reported.',
        ];
    }

    /**
     * MySQL: KEY_COLUMN_USAGE (the foreign-key referencing columns) left-joined against
     * STATISTICS at SEQ_IN_INDEX = 1 (the leading index column). A foreign-key column
     * with no matching leading index column is reported. Scoped to DATABASE().
     *
     * @return array<string, mixed>
     */
    private function mysqlReport(?string $table): array
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

        return [
            'driver' => 'mysql',
            'detection' => 'definitive',
            'missing_indexes' => $rows,
            'note' => 'InnoDB auto-indexes foreign keys; a referencing column with no leading index column is reported.',
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
