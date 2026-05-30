<?php

use Anilcancakir\LaravelAgentMcp\Tools\HorizonStatusTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server whose only registered tool is HorizonStatusTool, keeping these
// tests isolated from AgentMcpServer.
//
// Horizon is intentionally NOT a dependency of this package, so its classes do
// not exist at analyse time. We therefore reference every Horizon FQCN as a
// plain string literal (never `::class`): that keeps Pint's
// fully_qualified_strict_types fixer from hoisting them into top-of-file `use`
// imports of non-existent classes, and keeps PHPStan from flagging class.notFound.

const HORIZON_MARKER = 'Laravel\Horizon\Horizon';

const HORIZON_WORKLOAD_REPOSITORY = 'Laravel\Horizon\Contracts\WorkloadRepository';

const HORIZON_JOB_REPOSITORY = 'Laravel\Horizon\Contracts\JobRepository';

const HORIZON_METRICS_REPOSITORY = 'Laravel\Horizon\Contracts\MetricsRepository';

const HORIZON_SUPERVISOR_REPOSITORY = 'Laravel\Horizon\Contracts\SupervisorRepository';

const HORIZON_MASTER_SUPERVISOR_REPOSITORY = 'Laravel\Horizon\Contracts\MasterSupervisorRepository';

/**
 * Inline stub server that hosts HorizonStatusTool for this test file only.
 */
final class HorizonStatusStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        HorizonStatusTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's own provider populates the injected Request (method-injection
    // contract); auto-discovered in production, registered explicitly here.
    app()->register(McpServiceProvider::class);

    // The only gate left at the tool is the per-tool enable flag.
    config()->set('agent-mcp.tools.horizon_status', true);
    config()->set('agent-mcp.audit.enabled', false);
});

// --- tool-enabled gate ---

it('denies the call when the tool is disabled in config', function (): void {
    config()->set('agent-mcp.tools.horizon_status', false);

    HorizonStatusStubServer::tool(HorizonStatusTool::class, [])
        ->assertHasErrors();
});

// --- not-available branch (Horizon absent from the test dependencies) ---

it('returns available:false cleanly when Horizon is not installed', function (): void {
    // The tool must detect Horizon's absence and return a structured payload,
    // never fatal on a missing class at load or call time. Horizon is not a
    // dependency of this package, so the marker class does not exist; this test
    // is declared before the faked-available test, which is the only one that
    // defines the marker (via eval).
    expect(class_exists(HORIZON_MARKER, false))->toBeFalse();

    HorizonStatusStubServer::tool(HorizonStatusTool::class, [])
        ->assertOk()
        ->assertSee('"available": false')
        ->assertSee('horizon not installed');
});

// --- available branch (Horizon repositories faked in the container) ---
//
// Horizon is absent, so its contract interfaces cannot be type-hinted. We make
// class_exists('Laravel\Horizon\Horizon') true by defining a genuine stub class
// at that FQCN, and bind plain repository doubles keyed by the string contract
// FQCNs. This exercises the available branch end to end; with the real package
// present, the same string FQCNs resolve the real repositories.

it('returns workload, counts, metrics and supervisors when Horizon is bound', function (): void {
    // 1. Define a genuine Horizon marker class so class_exists() detects it.
    if (! class_exists(HORIZON_MARKER, false)) {
        eval('namespace Laravel\\Horizon; class Horizon {}');
    }

    // 2. Bind plain doubles for each repository contract by string FQCN.
    app()->bind(HORIZON_WORKLOAD_REPOSITORY, fn () => new class
    {
        /**
         * @return array<int, array<string, mixed>>
         */
        public function get(): array
        {
            return [
                [
                    'name' => 'default',
                    'length' => 12,
                    'wait' => 3,
                    'processes' => 4,
                ],
            ];
        }
    });

    app()->bind(HORIZON_JOB_REPOSITORY, fn () => new class
    {
        public function countPending(): int
        {
            return 12;
        }

        public function countFailed(): int
        {
            return 2;
        }

        public function countRecent(): int
        {
            return 99;
        }
    });

    app()->bind(HORIZON_METRICS_REPOSITORY, fn () => new class
    {
        public function throughput(): int
        {
            return 500;
        }

        public function jobsProcessedPerMinute(): int
        {
            return 42;
        }
    });

    app()->bind(HORIZON_SUPERVISOR_REPOSITORY, fn () => new class
    {
        /**
         * @return array<int, array<string, mixed>>
         */
        public function all(): array
        {
            return [
                ['name' => 'supervisor-1', 'status' => 'running'],
            ];
        }
    });

    app()->bind(HORIZON_MASTER_SUPERVISOR_REPOSITORY, fn () => new class
    {
        /**
         * @return array<int, array<string, mixed>>
         */
        public function all(): array
        {
            return [
                ['name' => 'master', 'status' => 'running'],
            ];
        }
    });

    $response = HorizonStatusStubServer::tool(HorizonStatusTool::class, [])
        ->assertOk()
        ->assertSee('"available": true');

    $response->assertSee('workload');
    $response->assertSee('supervisor-1');
    $response->assertSee('42');
    $response->assertSee('99');
});
