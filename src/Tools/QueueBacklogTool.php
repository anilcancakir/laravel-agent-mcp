<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Throwable;

/**
 * MCP tool: queue_backlog
 *
 * Reports the pending job count per connection and queue name, using only
 * framework-native queue APIs (no new dependencies). Every size() call is
 * wrapped in try/catch because SQS and other remote drivers throw without
 * credentials rather than returning zero.
 *
 * The database driver additionally computes a strict-pending count:
 * jobs that are not reserved and are already available (available_at <= now).
 * This is more accurate than size() for detecting genuinely-stuck jobs.
 *
 * Queue names are enumerated from the jobs table (database driver) or from a
 * configured scan pattern (redis). The sync driver is reported as N/A because
 * it executes inline with no persistent queue.
 *
 * CONNECTION BOUNDARY: the jobs-table reads use the connection the queue is
 * configured to use (queue.connections.*.connection), which may differ from the
 * package's hardened read-only clone. Only read-only query-builder methods are
 * used (no write call exists); the dedicated readonly DB grant is the enforcement
 * boundary for these reads. See the README security model for the rationale.
 */
#[Name('queue_backlog')]
#[Description(<<<'TEXT'
    Report pending job counts per connection and queue. Reach for this first when a user reports slow or missing background processing, before digging into job code.

    Usage:
    - Omit `connection` and `queue` to enumerate everything; provide either to scope the result.
    - The database driver also reports a strict-pending count. The sync driver has no backlog and returns a note instead of a number.
    - Pair with queue_failed_jobs for failure detail and horizon_status when Horizon is deployed.
    - Read-only.
    TEXT)]
class QueueBacklogTool extends AbstractAgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'connection' => $schema->string()
                ->nullable()
                ->description('Scope results to a single connection name. Omit to enumerate all connections.'),
            'queue' => $schema->string()
                ->nullable()
                ->description('Scope results to a single queue name within each connection.'),
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

        // 3. Resolve scope filters from the request.
        $connectionFilter = $request->get('connection');
        $queueFilter = $request->get('queue');

        // 4. Enumerate every configured connection (or just the requested one).
        $allConnections = (array) config('queue.connections', []);

        if ($connectionFilter !== null && $connectionFilter !== '') {
            $allConnections = array_filter(
                $allConnections,
                fn (string $key): bool => $key === $connectionFilter,
                ARRAY_FILTER_USE_KEY,
            );
        }

        // 5. Build the backlog report per connection.
        $results = [];

        foreach ($allConnections as $name => $connectionConfig) {
            $driver = $connectionConfig['driver'] ?? 'unknown';

            $results[$name] = $this->inspectConnection((string) $name, (string) $driver, $connectionConfig, $queueFilter);
        }

        // 6. Redact and return.
        $redacted = $this->redactor()->redactArray($results);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * Inspect a single queue connection and return its backlog data.
     *
     * @param  array<string, mixed>  $connectionConfig
     * @return array<string, mixed>
     */
    private function inspectConnection(
        string $name,
        string $driver,
        array $connectionConfig,
        mixed $queueFilter,
    ): array {
        // Sync driver has no persistent queue: report not_applicable rather than zero.
        if ($driver === 'sync') {
            return ['note' => 'not_applicable'];
        }

        $queues = $this->resolveQueueNames($driver, $connectionConfig, $queueFilter);

        $queueDetails = [];

        foreach ($queues as $queueName) {
            $queueDetails[$queueName] = $this->inspectQueue($name, $driver, $connectionConfig, (string) $queueName);
        }

        return [
            'driver' => $driver,
            'queues' => $queueDetails,
        ];
    }

    /**
     * Enumerate queue names for the given driver.
     *
     * Database: read distinct queue names from the jobs table.
     * Other drivers: fall back to the configured default queue name.
     *
     * @param  array<string, mixed>  $connectionConfig
     * @return array<int, string>
     */
    private function resolveQueueNames(string $driver, array $connectionConfig, mixed $queueFilter): array
    {
        if ($queueFilter !== null && $queueFilter !== '') {
            return [(string) $queueFilter];
        }

        if ($driver === 'database') {
            $dbConnection = $connectionConfig['connection'] ?? config('database.default');
            $table = $connectionConfig['table'] ?? 'jobs';

            try {
                $names = DB::connection((string) $dbConnection)
                    ->table((string) $table)
                    ->distinct()
                    ->pluck('queue')
                    ->toArray();

                return array_values(
                    array_filter($names, fn (mixed $n): bool => is_string($n) && $n !== ''),
                );
            } catch (Throwable) {
                // DB unreachable: fall through to the default queue name.
            }
        }

        // Default fallback: use the configured queue name.
        $default = $connectionConfig['queue'] ?? 'default';

        return [(string) $default];
    }

    /**
     * Return the size and strict-pending count for a single queue on a connection.
     *
     * @param  array<string, mixed>  $connectionConfig
     * @return array<string, mixed>
     */
    private function inspectQueue(
        string $connectionName,
        string $driver,
        array $connectionConfig,
        string $queueName,
    ): array {
        // Attempt to retrieve the queue size through the Queue facade.
        // Remote drivers (SQS, etc.) may throw without credentials.
        try {
            $size = app('queue')->connection($connectionName)->size($queueName);
        } catch (Throwable $e) {
            $size = ['error' => $e->getMessage()];
        }

        $result = ['size' => $size];

        // Database driver: compute the strict-pending count (not reserved, available now).
        if ($driver === 'database') {
            $dbConnection = $connectionConfig['connection'] ?? config('database.default');
            $table = $connectionConfig['table'] ?? 'jobs';

            try {
                $strictPending = DB::connection((string) $dbConnection)
                    ->table((string) $table)
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', now()->timestamp)
                    ->where('queue', $queueName)
                    ->count();

                $result['strict_pending'] = $strictPending;
            } catch (Throwable $e) {
                $result['strict_pending'] = ['error' => $e->getMessage()];
            }
        }

        return $result;
    }
}
