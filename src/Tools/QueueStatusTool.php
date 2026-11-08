<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

/**
 * MCP tool: queue_status
 *
 * One entry point for queue health, split into three sections so the agent pays for
 * one tool definition instead of three: the pending backlog per connection and queue,
 * the failed-job store, and a Laravel Horizon snapshot.
 *
 * SECURITY: the raw failed-job payload is never emitted. Only displayName, maxTries and
 * timeout survive from the decoded payload, and the exception is cut to its first line.
 *
 * CONNECTION BOUNDARY: the jobs and failed-jobs tables are read on the connection the
 * queue is configured to use, which may differ from the package's hardened read-only
 * clone. Only read-only query-builder methods are used; the dedicated readonly DB grant
 * is the enforcement boundary. Remote drivers (SQS and others) throw without credentials,
 * so every size() call is wrapped rather than trusted.
 *
 * Horizon is not a dependency: it is detected at runtime and referenced by string FQCN
 * only, so the section answers {available:false} instead of fatalling when it is absent.
 * No Horizon mutator is ever called.
 */
#[Name('queue_status')]
#[Description(<<<'TEXT'
    Report queue health: pending backlog, failed jobs and Laravel Horizon status. Reach for this first when background processing is slow, stuck or failing, before digging into job code.

    Usage:
    - `section` picks the view and defaults to backlog.
    - backlog: pending job count per connection and queue; the database driver adds a strict_pending count of jobs that are unreserved and already available. The sync driver has no backlog and returns a note instead of a number.
    - failed: per-job detail (job class, exception first line, failed_at), newest first, capped by `limit` with total and truncated when cut. Set `summary` true for counts grouped by queue and connection instead. The raw job payload is never returned.
    - horizon: workload, metrics and supervisor state, or {available:false} when Horizon is not installed; treat that as not deployed, not an error.
    - `connection` and `queue` scope the backlog and failed sections.
    TEXT)]
#[IsReadOnly]
class QueueStatusTool extends AbstractAgentTool
{
    /**
     * Failed rows returned when the caller does not pass a limit.
     */
    private const DEFAULT_LIMIT = 25;

    /**
     * Hard ceiling for the failed list, whatever the caller asks for.
     */
    private const MAX_LIMIT = 100;

    /**
     * Marker class proving Horizon is installed. Referenced as a string only.
     */
    private const HORIZON_MARKER = 'Laravel\Horizon\Horizon';

    /**
     * Horizon repository contracts, resolved by string FQCN from the container.
     */
    private const WORKLOAD_REPOSITORY = 'Laravel\Horizon\Contracts\WorkloadRepository';

    private const JOB_REPOSITORY = 'Laravel\Horizon\Contracts\JobRepository';

    private const METRICS_REPOSITORY = 'Laravel\Horizon\Contracts\MetricsRepository';

    private const SUPERVISOR_REPOSITORY = 'Laravel\Horizon\Contracts\SupervisorRepository';

    private const MASTER_SUPERVISOR_REPOSITORY = 'Laravel\Horizon\Contracts\MasterSupervisorRepository';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'section' => $schema->string()
                ->enum(['backlog', 'failed', 'horizon'])
                ->description('Which view to return. Defaults to backlog.'),
            'connection' => $schema->string()
                ->nullable()
                ->description('Scope backlog or failed results to a single connection name.'),
            'queue' => $schema->string()
                ->nullable()
                ->description('Scope backlog or failed results to a single queue name.'),
            'summary' => $schema->boolean()
                ->nullable()
                ->description('Failed section only: return counts grouped by queue and connection instead of the list.'),
            'limit' => $schema->integer()
                ->nullable()
                ->description('Failed list only: max jobs to return (default 25). Capped at 100.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Resolve the section, then audit the invocation shape (keys + types, never values).
        $section = $this->requestedSection($request, ['backlog', 'failed', 'horizon']);

        $this->audit($this->argumentShape($request->all()), $section);

        // 3. Run the section; jsonResponse redacts and encodes.
        return $this->jsonResponse(match ($section) {
            'failed' => $this->failed($request),
            'horizon' => $this->horizon(),
            default => $this->backlog($request),
        });
    }

    /**
     * Pending job counts for every configured connection, or just the requested one.
     *
     * @return array<string, mixed>
     */
    private function backlog(Request $request): array
    {
        $connectionFilter = $request->get('connection');
        $queueFilter = $request->get('queue');

        $allConnections = (array) config('queue.connections', []);

        if ($connectionFilter !== null && $connectionFilter !== '') {
            $allConnections = array_filter(
                $allConnections,
                fn (string $key): bool => $key === $connectionFilter,
                ARRAY_FILTER_USE_KEY,
            );
        }

        $results = [];

        foreach ($allConnections as $name => $connectionConfig) {
            $driver = $connectionConfig['driver'] ?? 'unknown';

            $results[$name] = $this->inspectConnection((string) $name, (string) $driver, $connectionConfig, $queueFilter);
        }

        return $results;
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

        $queueDetails = [];

        foreach ($this->resolveQueueNames($driver, $connectionConfig, $queueFilter) as $queueName) {
            $queueDetails[$queueName] = $this->inspectQueue($name, $driver, $connectionConfig, $queueName);
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

        return [(string) ($connectionConfig['queue'] ?? 'default')];
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
        // Remote drivers (SQS, etc.) may throw without credentials.
        try {
            $size = app('queue')->connection($connectionName)->size($queueName);
        } catch (Throwable $e) {
            $size = ['error' => $e->getMessage()];
        }

        $result = ['size' => $size];

        // Database driver: strict-pending is not reserved and available now.
        if ($driver === 'database') {
            $dbConnection = $connectionConfig['connection'] ?? config('database.default');
            $table = $connectionConfig['table'] ?? 'jobs';

            try {
                $result['strict_pending'] = DB::connection((string) $dbConnection)
                    ->table((string) $table)
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', now()->timestamp)
                    ->where('queue', $queueName)
                    ->count();
            } catch (Throwable $e) {
                $result['strict_pending'] = ['error' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * Failed jobs as a per-queue count summary or a capped newest-first list.
     *
     * @return array<string, mixed>
     */
    private function failed(Request $request): array
    {
        try {
            $query = $this->failedQuery($request->get('connection'), $request->get('queue'));

            return (bool) $request->get('summary', false)
                ? $this->failedSummary($query)
                : $this->failedList($query, $this->resolveLimit($request->get('limit')));
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * The failed-jobs table on the connection the failer is configured to use,
     * narrowed by the optional connection and queue filters.
     */
    private function failedQuery(mixed $connectionFilter, mixed $queueFilter): Builder
    {
        $config = config('queue.failed');
        $config = is_array($config) ? $config : [];

        $query = DB::connection((string) ($config['database'] ?? config('database.default')))
            ->table((string) ($config['table'] ?? 'failed_jobs'));

        if ($connectionFilter !== null && $connectionFilter !== '') {
            $query->where('connection', (string) $connectionFilter);
        }

        if ($queueFilter !== null && $queueFilter !== '') {
            $query->where('queue', (string) $queueFilter);
        }

        return $query;
    }

    /**
     * Count summary grouped by queue and connection, read straight from the table
     * to avoid loading every row through the failer.
     *
     * @return array<string, mixed>
     */
    private function failedSummary(Builder $query): array
    {
        $rows = $query
            ->selectRaw('connection, queue, COUNT(*) as count')
            ->groupBy('connection', 'queue')
            ->get()
            ->map(fn (object $row): array => [
                'connection' => $row->connection,
                'queue' => $row->queue,
                'count' => (int) $row->count,
            ])
            ->toArray();

        return ['summary' => $rows];
    }

    /**
     * Newest failed jobs with safe fields only. The total comes from a count over the
     * same filters, which is also how a cut list is detected without fetching extra rows.
     *
     * @return array<string, mixed>
     */
    private function failedList(Builder $query, int $limit): array
    {
        $total = (clone $query)->count();

        // The payload is fetched but immediately decoded and stripped; the raw string never leaves sanitizeRow().
        $jobs = $query
            ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->sanitizeRow($row))
            ->toArray();

        $result = [
            'jobs' => $jobs,
            'total' => $total,
        ];

        if ($total > $limit) {
            $result['truncated'] = true;
        }

        return $result;
    }

    /**
     * Convert a raw failed-job row into a safe, limited output shape.
     *
     * @return array<string, mixed>
     */
    private function sanitizeRow(object $row): array
    {
        $decoded = json_decode((string) $row->payload, associative: false);

        $jobClass = null;
        $maxTries = null;
        $timeout = null;

        if ($decoded !== null) {
            $jobClass = isset($decoded->displayName) ? (string) $decoded->displayName : null;
            $maxTries = $decoded->maxTries ?? null;
            $timeout = $decoded->timeout ?? null;
        }

        // First line only: the rest is a stack trace.
        $exceptionFull = (string) $row->exception;
        $exceptionSummary = strtok($exceptionFull, "\n") ?: $exceptionFull;

        return [
            'id' => $row->id,
            'uuid' => $row->uuid ?? null,
            'connection' => $row->connection,
            'queue' => $row->queue,
            'job_class' => $jobClass,
            'max_tries' => $maxTries,
            'timeout' => $timeout,
            'exception_summary' => $exceptionSummary,
            'failed_at' => $row->failed_at,
        ];
    }

    /**
     * Clamp the caller-supplied limit into 1..MAX_LIMIT, defaulting when it is absent
     * or non-numeric.
     */
    private function resolveLimit(mixed $limit): int
    {
        if (! is_numeric($limit)) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min(self::MAX_LIMIT, (int) $limit));
    }

    /**
     * Horizon snapshot, or a structured unavailable payload when it is not deployed.
     *
     * @return array<string, mixed>
     */
    private function horizon(): array
    {
        if (! $this->horizonAvailable()) {
            return [
                'available' => false,
                'reason' => 'horizon not installed',
            ];
        }

        return [
            'available' => true,
            'workload' => app()->make(self::WORKLOAD_REPOSITORY)->get(),
            'jobs' => $this->horizonJobCounts(),
            'metrics' => $this->horizonMetrics(),
            'supervisors' => app()->make(self::SUPERVISOR_REPOSITORY)->all(),
            'master_supervisors' => app()->make(self::MASTER_SUPERVISOR_REPOSITORY)->all(),
        ];
    }

    /**
     * Whether Horizon is installed AND its repositories are bound. The marker
     * class is checked by string so the absent-package case never loads it.
     */
    private function horizonAvailable(): bool
    {
        return class_exists(self::HORIZON_MARKER)
            && app()->bound(self::JOB_REPOSITORY);
    }

    /**
     * Aggregate job counts across the recent window.
     *
     * @return array<string, int>
     */
    private function horizonJobCounts(): array
    {
        $jobs = app()->make(self::JOB_REPOSITORY);

        return [
            'pending' => $jobs->countPending(),
            'failed' => $jobs->countFailed(),
            'recent' => $jobs->countRecent(),
        ];
    }

    /**
     * Throughput and per-minute processing rate.
     *
     * @return array<string, mixed>
     */
    private function horizonMetrics(): array
    {
        $metrics = app()->make(self::METRICS_REPOSITORY);

        return [
            'throughput' => $metrics->throughput(),
            'jobs_per_minute' => $metrics->jobsProcessedPerMinute(),
        ];
    }
}
