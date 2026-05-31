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
 * MCP tool: queue_failed_jobs
 *
 * Reads the failed job store (app('queue.failer')) to surface either a
 * per-queue/connection summary or a pageable list of individual failures.
 *
 * SECURITY (Oracle IMP4): the raw job payload is NEVER emitted. Only three
 * fields from the decoded payload are included: displayName, maxTries, and
 * timeout. The exception text is trimmed to its first line to avoid emitting
 * a full stack trace. The output is run through the OutputRedactor as a final
 * net.
 *
 * WRITE-SAFETY: this tool never calls forget/flush/retry/delete/trim on the
 * failer. It is strictly read-only.
 *
 * CONNECTION BOUNDARY: the failed-jobs table is read on the connection it is
 * configured to use (queue.failed.database), which may differ from the package's
 * hardened read-only clone. Only read-only query-builder methods are used; the
 * dedicated readonly DB grant is the enforcement boundary. See the README.
 */
#[Name('queue_failed_jobs')]
#[Description(<<<'TEXT'
    Inspect failed background jobs from the failed-jobs table. Use it when investigating why jobs are failing.

    Usage:
    - Set `summary` true for counts grouped by queue and connection; leave it off for per-job detail (job class, exception first line, failed_at).
    - Scope with `connection` and `queue` when needed.
    - The raw job payload is never returned.
    - Read-only.
    TEXT)]
class QueueFailedJobsTool extends AbstractAgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->boolean()
                ->nullable()
                ->description('When true, return a count grouped by queue and connection instead of the full list.'),
            'connection' => $schema->string()
                ->nullable()
                ->description('Scope the list or summary to a single connection name.'),
            'queue' => $schema->string()
                ->nullable()
                ->description('Scope the list or summary to a single queue name.'),
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

        // 3. Resolve scope filters.
        $summaryMode = (bool) $request->get('summary', false);
        $connectionFilter = $request->get('connection');
        $queueFilter = $request->get('queue');

        // 4. Dispatch to summary or list path.
        $result = $summaryMode
            ? $this->buildSummary($connectionFilter, $queueFilter)
            : $this->buildList($connectionFilter, $queueFilter);

        // 5. Redact and return.
        $redacted = $this->redactor()->redactArray($result);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * Build a count summary grouped by queue and connection.
     *
     * Reads directly from the configured failed-jobs table to avoid loading
     * every row into memory via app('queue.failer')->all().
     *
     * @return array<string, mixed>
     */
    private function buildSummary(mixed $connectionFilter, mixed $queueFilter): array
    {
        $failedConfig = $this->failedConfig();
        $database = $failedConfig['database'] ?? config('database.default');
        $table = $failedConfig['table'] ?? 'failed_jobs';

        try {
            $query = DB::connection((string) $database)->table((string) $table);

            if ($connectionFilter !== null && $connectionFilter !== '') {
                $query->where('connection', (string) $connectionFilter);
            }

            if ($queueFilter !== null && $queueFilter !== '') {
                $query->where('queue', (string) $queueFilter);
            }

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
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Build a list of failed jobs with safe, limited fields per row.
     *
     * Only displayName, maxTries, and timeout are extracted from the payload.
     * The raw payload string is NEVER included in the output.
     *
     * @return array<string, mixed>
     */
    private function buildList(mixed $connectionFilter, mixed $queueFilter): array
    {
        $failedConfig = $this->failedConfig();
        $database = $failedConfig['database'] ?? config('database.default');
        $table = $failedConfig['table'] ?? 'failed_jobs';

        try {
            $query = DB::connection((string) $database)->table((string) $table);

            if ($connectionFilter !== null && $connectionFilter !== '') {
                $query->where('connection', (string) $connectionFilter);
            }

            if ($queueFilter !== null && $queueFilter !== '') {
                $query->where('queue', (string) $queueFilter);
            }

            // Fetch only the fields we need; payload is fetched but immediately decoded
            // and stripped down. The raw payload string never exits this method.
            $rows = $query
                ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
                ->orderByDesc('id')
                ->get()
                ->map(fn (object $row): array => $this->sanitizeRow($row))
                ->toArray();

            return ['jobs' => $rows];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Convert a raw failed-job row into a safe, limited output shape.
     *
     * The raw payload is decoded and only displayName/maxTries/timeout are
     * extracted (Oracle IMP4: never emit raw payload).
     *
     * @return array<string, mixed>
     */
    private function sanitizeRow(object $row): array
    {
        // Decode the payload but emit only the three permitted keys.
        $decoded = json_decode((string) $row->payload, associative: false);

        $jobClass = null;
        $maxTries = null;
        $timeout = null;

        if ($decoded !== null) {
            $jobClass = isset($decoded->displayName) ? (string) $decoded->displayName : null;
            $maxTries = isset($decoded->maxTries) ? $decoded->maxTries : null;
            $timeout = isset($decoded->timeout) ? $decoded->timeout : null;
        }

        // Trim the exception to its first line only.
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
     * Resolve the failed-job queue configuration.
     *
     * @return array<string, mixed>
     */
    private function failedConfig(): array
    {
        $config = config('queue.failed');

        return is_array($config) ? $config : [];
    }
}
