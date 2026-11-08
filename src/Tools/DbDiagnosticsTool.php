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

/**
 * MCP tool: db_diagnostics (default OFF)
 *
 * Server-side runtime diagnostics in two sections, read-only, over the hardened
 * readonly connection. One config flag, agent-mcp.tools.db_diagnostics, opens both
 * sections: they share the same privilege requirement (pg_monitor / pg_read_all_stats
 * on PostgreSQL, performance_schema access on MySQL), so the operator grants it once
 * and opts in once.
 *
 * Both backing stores are engine-specific and privilege-dependent, so each section
 * degrades to a structured {available:false, reason} payload instead of erroring:
 *
 *   - slow_queries: PostgreSQL reads pg_stat_statements (detected via pg_extension
 *     first), MySQL reads performance_schema.events_statements_summary_by_digest
 *     scoped to DATABASE() with picosecond timers converted to milliseconds.
 *   - locks: PostgreSQL pairs blocked and blocking sessions from pg_locks and
 *     pg_stat_activity scoped to current_database(); MySQL reads PROCESSLIST rows in a
 *     lock-waiting state. The result is a single instant in time.
 *   - SQLite and unknown engines have neither store, so {available:false}.
 *
 * The tool NEVER runs query plans with execution, takes a lock, or calls any
 * session-killing or advisory-lock function; it only reads.
 */
#[Name('db_diagnostics')]
#[Description(<<<'TEXT'
    Database server diagnostics: slow queries (top statements by mean execution time) and active locks (blocked and blocking sessions). Use slow_queries to find the worst-performing statements before looking at index structure with db_health, and locks when investigating lock contention, stuck queries or deadlock symptoms.

    - Off by default and one flag opens both sections; treat a denial as the expected default, not an error.
    - slow_queries needs pg_stat_statements (PostgreSQL) or performance_schema (MySQL); `limit` caps its statements (default 20, at most 100) and does not apply to locks.
    - locks reads pg_locks and pg_stat_activity (PostgreSQL, full visibility needs pg_monitor) or PROCESSLIST (MySQL); it is a snapshot, so a lock may be gone by the time you read it.
    - Returns {available:false, reason} when the engine, extension or privilege is missing, and always on SQLite.
    TEXT)]
#[IsReadOnly]
class DbDiagnosticsTool extends AbstractAgentTool
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
            'section' => $schema->string()
                ->enum(['slow_queries', 'locks'])
                ->description('Which diagnostic to run (default slow_queries).'),
            'limit' => $schema->integer()
                ->nullable()
                ->description('Max statements for slow_queries (default 20). Capped at 100.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Validate the section, then audit the invocation shape (keys + types, never values).
        $section = $this->requestedSection($request, ['slow_queries', 'locks']);
        $this->audit($this->argumentShape($request->all()), $section);

        // 3. Run the section.
        $payload = $section === 'locks'
            ? $this->locks()
            : $this->slowQueries($this->resolveLimit($request->get('limit')));

        return $this->jsonResponse($payload);
    }

    /**
     * Dispatch the slow-statement section on the engine; unsupported or undetected
     * backends degrade.
     *
     * @return array<string, mixed>
     */
    private function slowQueries(int $limit): array
    {
        return match ($this->catalog->driver()) {
            'pgsql' => $this->postgresSlowQueries($limit),
            'mysql' => $this->mysqlSlowQueries($limit),
            default => [
                'available' => false,
                'reason' => 'slow-query statistics are not available on the sqlite engine',
            ],
        };
    }

    /**
     * Dispatch the lock-snapshot section on the engine; SQLite has no lock catalog.
     *
     * @return array<string, mixed>
     */
    private function locks(): array
    {
        return match ($this->catalog->driver()) {
            'pgsql' => $this->postgresLocks(),
            'mysql' => $this->mysqlLocks(),
            default => [
                'available' => false,
                'reason' => 'active-lock inspection is not available on the sqlite engine',
            ],
        };
    }

    /**
     * PostgreSQL: detect pg_stat_statements via pg_extension, then read the top
     * statements by mean execution time. The view exposes numeric stats even when
     * query text is unavailable, so those rows still carry timing data.
     *
     * @return array<string, mixed>
     */
    private function postgresSlowQueries(int $limit): array
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
    private function mysqlSlowQueries(int $limit): array
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
     * PostgreSQL: pair each blocked session with the session blocking it via the
     * pg_locks self-join, decorated with pg_stat_activity. Scoped to the current
     * database. Read-only: no lock is taken and no backend is terminated.
     *
     * @return array<string, mixed>
     */
    private function postgresLocks(): array
    {
        $sql = 'SELECT '
            .'blocked.pid AS blocked_pid, '
            .'blocked_activity.usename AS blocked_user, '
            .'blocked_activity.query AS blocked_query, '
            .'blocking.pid AS blocking_pid, '
            .'blocking_activity.usename AS blocking_user, '
            .'blocking_activity.query AS blocking_query '
            .'FROM pg_locks blocked '
            .'JOIN pg_stat_activity blocked_activity ON blocked_activity.pid = blocked.pid '
            .'JOIN pg_locks blocking ON blocking.locktype = blocked.locktype '
            .'AND blocking.database IS NOT DISTINCT FROM blocked.database '
            .'AND blocking.relation IS NOT DISTINCT FROM blocked.relation '
            .'AND blocking.pid <> blocked.pid '
            .'JOIN pg_stat_activity blocking_activity ON blocking_activity.pid = blocking.pid '
            .'WHERE NOT blocked.granted '
            .'AND blocking.granted '
            .'AND blocked_activity.datname = current_database()';

        $rows = array_map(fn (object $row): array => [
            'blocked_pid' => (int) $row->blocked_pid,
            'blocked_user' => $row->blocked_user,
            'blocked_query' => $row->blocked_query,
            'blocking_pid' => (int) $row->blocking_pid,
            'blocking_user' => $row->blocking_user,
            'blocking_query' => $row->blocking_query,
        ], $this->catalog->select($sql));

        return [
            'available' => true,
            'engine' => 'pgsql',
            'source' => 'pg_locks + pg_stat_activity',
            'note' => 'Point-in-time snapshot. Without pg_monitor / pg_read_all_stats a readonly role may only see its own sessions, so blocking rows can be incomplete.',
            'blocked_sessions' => $rows,
        ];
    }

    /**
     * MySQL: the current process list, scoped to the connected database. This is a
     * point-in-time view; deeper lock-wait edges need privilege-gated
     * performance_schema tables not assumed present for a readonly user.
     *
     * @return array<string, mixed>
     */
    private function mysqlLocks(): array
    {
        $databaseScope = $this->catalog->mysqlDatabaseScope('DB');

        $sql = 'SELECT '
            .'ID AS process_id, '
            .'USER AS user, '
            .'COMMAND AS command, '
            .'TIME AS seconds, '
            .'STATE AS state, '
            .'INFO AS query '
            .'FROM information_schema.PROCESSLIST '
            ."WHERE {$databaseScope} "
            ."AND STATE LIKE '%lock%'";

        $rows = array_map(fn (object $row): array => [
            'process_id' => (int) $row->process_id,
            'user' => $row->user,
            'command' => $row->command,
            'seconds' => (int) $row->seconds,
            'state' => $row->state,
            'query' => $row->query,
        ], $this->catalog->select($sql));

        return [
            'available' => true,
            'engine' => 'mysql',
            'source' => 'information_schema.PROCESSLIST',
            'note' => 'Point-in-time snapshot of sessions in a lock-waiting state. Full lock-wait graph needs performance_schema access.',
            'lock_waiting_sessions' => $rows,
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
}
