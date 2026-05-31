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

/**
 * MCP tool: db_active_locks (default OFF)
 *
 * Point-in-time snapshot of blocked / blocking sessions, read-only, over the
 * hardened readonly connection. The lock catalog is engine-specific and
 * privilege-dependent, so the tool degrades to {available:false} rather than
 * erroring when it cannot read it:
 *
 *   - PostgreSQL: pg_locks joined to pg_stat_activity to pair a blocked session
 *     with the session holding the conflicting lock. Scoped to the current
 *     database (datname = current_database()). A normal readonly role sees only
 *     its own rows without pg_monitor / pg_read_all_stats, so the result carries a
 *     privilege caveat note.
 *   - MySQL: information_schema.PROCESSLIST for the current session set; richer
 *     lock-wait detail needs performance_schema.data_lock_waits which is
 *     privilege-gated.
 *   - SQLite: single-writer file locking with no server-side lock catalog, so
 *     {available:false}.
 *
 * Default OFF + read-only. The tool NEVER calls any session-killing or advisory-
 * lock function on either engine; it only reads the lock state. The snapshot is
 * inherently a single instant in time.
 */
#[Name('db_active_locks')]
#[Description(<<<'TEXT'
    Take a point-in-time snapshot of blocked and blocking sessions and the locks they hold. Use it when investigating lock contention, stuck queries, or deadlock symptoms.

    Usage:
    - Off by default; treat a denial as the expected default.
    - Reflects the lock state at the instant of the call only; a lock may already be gone by the time you read the result. Treat it as a snapshot, not a live view.
    - PostgreSQL reads pg_locks and pg_stat_activity (full visibility needs pg_monitor); MySQL reads PROCESSLIST and performance_schema; it returns {available:false} on SQLite.
    - Read-only.
    TEXT)]
class DbActiveLocksTool extends AbstractAgentTool
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
        return [];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. Dispatch on the engine; SQLite has no lock catalog.
        $payload = match ($this->catalog->driver()) {
            'pgsql' => $this->postgres(),
            'mysql' => $this->mysql(),
            default => [
                'available' => false,
                'reason' => 'active-lock inspection is not available on the sqlite engine',
            ],
        };

        return $this->respond($payload);
    }

    /**
     * PostgreSQL: pair each blocked session with the session blocking it via the
     * pg_locks self-join, decorated with pg_stat_activity. Scoped to the current
     * database. Read-only: no lock is taken and no backend is terminated.
     *
     * @return array<string, mixed>
     */
    private function postgres(): array
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
    private function mysql(): array
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
