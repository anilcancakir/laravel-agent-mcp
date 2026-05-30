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
 * MCP tool: db_slow_queries (default OFF)
 *
 * Surfaces the slowest statements the database server has recorded, read-only,
 * over the hardened readonly connection. The backing store is engine-specific and
 * privilege-dependent, so the tool detects it first and degrades to a structured
 * {available:false} payload rather than erroring:
 *
 *   - PostgreSQL: the pg_stat_statements extension. Detected via pg_extension; when
 *     absent the tool returns {available:false}. When present it returns the top
 *     statements ordered by mean execution time. Numeric stats are always emitted
 *     even when the query text is NULL (track_activity_query_size / privilege).
 *   - MySQL: performance_schema.events_statements_summary_by_digest, scoped to the
 *     connected database via DATABASE(). Timer columns are picoseconds and are
 *     converted to milliseconds.
 *   - SQLite: there is no server-side statement store, so {available:false}.
 *
 * Default OFF: it needs pg_monitor / pg_read_all_stats (PostgreSQL) or
 * performance_schema access (MySQL); the operator opts in after granting them.
 * The tool NEVER runs query plans with execution or any statement-killing function.
 */
#[Name('db_slow_queries')]
class DbSlowQueriesTool extends AbstractAgentTool
{
    /**
     * Default number of statements to return when the caller does not specify.
     */
    private const DEFAULT_LIMIT = 20;

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
            'limit' => $schema->integer()
                ->nullable()
                ->description('Max statements to return (default 20). Capped at 100.'),
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

        // 3. Resolve the row cap.
        $limit = $this->resolveLimit($request->get('limit'));

        // 4. Dispatch on the engine; unsupported / undetected backends degrade.
        $payload = match ($this->catalog->driver()) {
            'pgsql' => $this->postgres($limit),
            'mysql' => $this->mysql($limit),
            default => [
                'available' => false,
                'reason' => 'slow-query statistics are not available on the sqlite engine',
            ],
        };

        return $this->respond($payload);
    }

    /**
     * PostgreSQL: detect pg_stat_statements via pg_extension, then read the top
     * statements by mean execution time. The view exposes numeric stats even when
     * query text is unavailable, so those rows still carry timing data.
     *
     * @return array<string, mixed>
     */
    private function postgres(int $limit): array
    {
        $hasExtension = $this->catalog->select(
            "SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'",
        );

        if ($hasExtension === []) {
            return [
                'available' => false,
                'reason' => 'pg_stat_statements extension is not installed',
            ];
        }

        $sql = 'SELECT '
            .'query, calls, total_exec_time, mean_exec_time, rows '
            .'FROM pg_stat_statements '
            .'ORDER BY mean_exec_time DESC '
            .'LIMIT ?';

        $rows = array_map(fn (object $row): array => [
            'query' => $row->query,
            'calls' => (int) $row->calls,
            'total_ms' => round((float) $row->total_exec_time, 3),
            'mean_ms' => round((float) $row->mean_exec_time, 3),
            'rows' => (int) $row->rows,
        ], $this->catalog->select($sql, [$limit]));

        return [
            'available' => true,
            'engine' => 'pgsql',
            'source' => 'pg_stat_statements',
            'statements' => $rows,
        ];
    }

    /**
     * MySQL: performance_schema digest summary scoped to the connected database.
     * Timer columns are picoseconds; convert the per-statement average to ms.
     *
     * @return array<string, mixed>
     */
    private function mysql(int $limit): array
    {
        $databaseScope = $this->catalog->mysqlDatabaseScope('SCHEMA_NAME');

        $sql = 'SELECT '
            .'DIGEST_TEXT AS query, '
            .'COUNT_STAR AS calls, '
            .'AVG_TIMER_WAIT AS avg_picoseconds, '
            .'SUM_TIMER_WAIT AS sum_picoseconds, '
            .'SUM_ROWS_SENT AS rows_sent '
            .'FROM performance_schema.events_statements_summary_by_digest '
            ."WHERE {$databaseScope} "
            .'ORDER BY AVG_TIMER_WAIT DESC '
            .'LIMIT ?';

        $rows = array_map(fn (object $row): array => [
            'query' => $row->query,
            'calls' => (int) $row->calls,
            'mean_ms' => round((float) $row->avg_picoseconds / 1_000_000_000, 3),
            'total_ms' => round((float) $row->sum_picoseconds / 1_000_000_000, 3),
            'rows_sent' => (int) $row->rows_sent,
        ], $this->catalog->select($sql, [$limit]));

        return [
            'available' => true,
            'engine' => 'mysql',
            'source' => 'performance_schema.events_statements_summary_by_digest',
            'statements' => $rows,
        ];
    }

    /**
     * Clamp the caller-supplied limit into a sane range (1..100), defaulting when
     * it is absent or non-numeric.
     */
    private function resolveLimit(mixed $limit): int
    {
        if (! is_numeric($limit)) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min(100, (int) $limit));
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
