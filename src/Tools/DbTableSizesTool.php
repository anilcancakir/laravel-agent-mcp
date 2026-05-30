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
use Throwable;

/**
 * MCP tool: db_table_sizes
 *
 * Reports per-table storage footprint over the hardened readonly connection,
 * branching on the engine via the shared CatalogQuery boundary:
 *
 *   - PostgreSQL: pg_total_relation_size / pg_relation_size / pg_indexes_size for
 *     bytes, plus n_live_tup / n_dead_tup for a dead-tuple percentage (bloat
 *     signal). Scoped to current_database() and the user schemas.
 *   - MySQL: information_schema.TABLES DATA_LENGTH / INDEX_LENGTH / TABLE_ROWS /
 *     DATA_FREE, scoped to DATABASE(). These are storage-engine estimates and are
 *     labelled as such.
 *   - SQLite: the dbstat virtual table when the build was compiled with it
 *     (exact per-table bytes), otherwise a graceful degrade to the whole-file size
 *     page_count * page_size (probe-then-degrade).
 *
 * An optional table argument scopes the report to a single table; it is validated
 * against knownTables() and passed to the catalog query only as a binding, never
 * interpolated.
 */
#[Name('db_table_sizes')]
class DbTableSizesTool extends AbstractAgentTool
{
    /**
     * The shared read-only catalog-SQL boundary (engine detect + bound SELECT).
     */
    private readonly CatalogQuery $catalog;

    public function __construct(
        ReadonlyConnectionResolver $connectionResolver,
        OutputRedactor $outputRedactor,
        AuditLogger $auditLogger,
        CatalogQuery $catalog,
    ) {
        parent::__construct($connectionResolver, $outputRedactor, $auditLogger);

        $this->catalog = $catalog;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()
                ->nullable()
                ->description('Optional table name to scope the report. Omit to size all tables.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. Resolve and validate the optional table scope before any binding.
        $table = $request->get('table');

        if ($table !== null && $table !== '') {
            if (! in_array((string) $table, $this->catalog->knownTables(), true)) {
                return Response::error("Unknown table: {$table}");
            }

            $table = (string) $table;
        } else {
            $table = null;
        }

        // 4. Dispatch on the engine.
        $payload = match ($this->catalog->driver()) {
            'pgsql' => $this->postgres($table),
            'mysql' => $this->mysql($table),
            'sqlite' => $this->sqlite($table),
            default => [
                'available' => false,
                'reason' => 'table sizes are not supported on this engine',
            ],
        };

        return $this->respond($payload);
    }

    /**
     * PostgreSQL: byte sizes via the relation-size functions plus a dead-tuple
     * percentage from the statistics collector. Scoped to user schemas in the
     * current database (no cross-database enumeration).
     *
     * @return array<string, mixed>
     */
    private function postgres(?string $table): array
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

        return [
            'engine' => 'pgsql',
            'source' => 'pg_total_relation_size + pg_stat_user_tables',
            'tables' => $rows,
        ];
    }

    /**
     * MySQL: storage-engine size estimates from information_schema.TABLES, scoped
     * to the connected database via DATABASE(). The values are engine estimates,
     * not an exact byte count, and are labelled as such.
     *
     * @return array<string, mixed>
     */
    private function mysql(?string $table): array
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

        return [
            'engine' => 'mysql',
            'source' => 'information_schema.TABLES (storage-engine estimates)',
            'note' => 'TABLE_ROWS and length columns are InnoDB estimates, not exact counts.',
            'tables' => $rows,
        ];
    }

    /**
     * SQLite: exact per-table bytes via the dbstat virtual table when the build
     * was compiled with SQLITE_ENABLE_DBSTAT_VTAB; otherwise a graceful degrade to
     * the whole-database file size (page_count * page_size), which cannot be split
     * per table. The dbstat path is probed and the degrade reported transparently.
     *
     * @return array<string, mixed>
     */
    private function sqlite(?string $table): array
    {
        if ($this->sqliteHasDbstat()) {
            return $this->sqliteDbstat($table);
        }

        return $this->sqlitePageCountDegrade();
    }

    /**
     * Per-table byte aggregation from the dbstat virtual table.
     *
     * @return array<string, mixed>
     */
    private function sqliteDbstat(?string $table): array
    {
        $sql = 'SELECT name AS table_name, SUM(pgsize) AS total_bytes '
            .'FROM dbstat '
            .'GROUP BY name';

        $bindings = [];

        if ($table !== null) {
            $sql = 'SELECT name AS table_name, SUM(pgsize) AS total_bytes '
                .'FROM dbstat WHERE name = ? GROUP BY name';
            $bindings[] = $table;
        }

        $sql .= ' ORDER BY total_bytes DESC';

        $rows = array_map(fn (object $row): array => [
            'table' => $row->table_name,
            'total_bytes' => (int) $row->total_bytes,
        ], $this->catalog->select($sql, $bindings));

        return [
            'engine' => 'sqlite',
            'source' => 'dbstat',
            'tables' => $rows,
        ];
    }

    /**
     * Degrade path when dbstat is not compiled in: the whole-file byte size only,
     * which SQLite cannot attribute per table.
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
     * Probe whether the SQLite build exposes the dbstat virtual table. A failed
     * probe (table does not exist) means the build lacks SQLITE_ENABLE_DBSTAT_VTAB
     * and the tool degrades to whole-file size.
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
     * Redact (defense-in-depth) and emit the payload as a JSON text response.
     *
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload): Response
    {
        $redacted = $this->redactor()->redactArray($payload);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
