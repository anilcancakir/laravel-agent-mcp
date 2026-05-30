<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool: horizon_status
 *
 * Read-only snapshot of a Laravel Horizon deployment: per-queue workload, job
 * counts, throughput metrics, and the supervisor / master-supervisor tree.
 *
 * Horizon is NOT a dependency of this package. The tool detects it at runtime
 * and is completely inert when it is absent: it references Horizon only through
 * string FQCNs (never a type-hinted `use`), so the file loads and the call
 * succeeds even on an installation without Horizon, returning a structured
 * {available:false} payload instead of fatalling on a missing class.
 *
 * Every repository is resolved from the container and queried read-only. The
 * tool NEVER calls any Horizon mutator (forget/flush/retry/delete/trim/pause/
 * continue/terminate); it only reads status.
 */
#[Name('horizon_status')]
class HorizonStatusTool extends AbstractAgentTool
{
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
        return [];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit the invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. Inert when Horizon is absent: structured payload, never a fatal.
        if (! $this->horizonAvailable()) {
            return $this->respond([
                'available' => false,
                'reason' => 'horizon not installed',
            ]);
        }

        // 4. Read the Horizon status snapshot through read-only repositories.
        return $this->respond([
            'available' => true,
            'workload' => $this->workload(),
            'jobs' => $this->jobCounts(),
            'metrics' => $this->metrics(),
            'supervisors' => $this->supervisors(),
            'master_supervisors' => $this->masterSupervisors(),
        ]);
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
     * Per-queue workload: name, length, wait, processes.
     *
     * @return array<int, mixed>
     */
    private function workload(): array
    {
        return app()->make(self::WORKLOAD_REPOSITORY)->get();
    }

    /**
     * Aggregate job counts across the recent window.
     *
     * @return array<string, int>
     */
    private function jobCounts(): array
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
    private function metrics(): array
    {
        $metrics = app()->make(self::METRICS_REPOSITORY);

        return [
            'throughput' => $metrics->throughput(),
            'jobs_per_minute' => $metrics->jobsProcessedPerMinute(),
        ];
    }

    /**
     * The supervisor tree (worker pools).
     *
     * @return array<int, mixed>
     */
    private function supervisors(): array
    {
        return app()->make(self::SUPERVISOR_REPOSITORY)->all();
    }

    /**
     * The master-supervisor tree (one per host).
     *
     * @return array<int, mixed>
     */
    private function masterSupervisors(): array
    {
        return app()->make(self::MASTER_SUPERVISOR_REPOSITORY)->all();
    }

    /**
     * Redact the payload (defense-in-depth) and emit it as a JSON text response.
     *
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload): Response
    {
        $redacted = $this->redactor()->redactArray($payload);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
