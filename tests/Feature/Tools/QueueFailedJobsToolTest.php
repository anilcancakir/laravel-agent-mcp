<?php

use Anilcancakir\LaravelAgentMcp\Tools\QueueFailedJobsTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only QueueFailedJobsTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts QueueFailedJobsTool for this test file only.
 */
final class QueueFailedJobsStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        QueueFailedJobsTool::class,
    ];
}

// Secret embedded in the payload that MUST NOT appear in the tool output.
const PAYLOAD_SECRET = 'SUPER_SECRET_PAYLOAD_TOKEN_abc123';

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.queue_failed_jobs', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build the fixture failed_jobs table on the readonly connection BEFORE the
    // tool hardens it (ReadonlyConnectionResolver sets PRAGMA query_only=ON on first access).
    Schema::connection('readonly')->dropIfExists('failed_jobs');
    Schema::connection('readonly')->create('failed_jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    // Seed a failed job whose payload carries the secret token (must NOT leak).
    DB::connection('readonly')->table('failed_jobs')->insert([
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\ImportJob',
            'maxTries' => 3,
            'timeout' => 60,
            'secret_data' => PAYLOAD_SECRET,
            'raw_input' => 'some sensitive user data',
        ]),
        'exception' => "Illuminate\\Database\\QueryException: SQLSTATE[42S02]\nmore lines here",
        'failed_at' => now()->toDateTimeString(),
    ]);

    // Second failed job on a different queue.
    DB::connection('readonly')->table('failed_jobs')->insert([
        'uuid' => 'ffffffff-0000-1111-2222-333333333333',
        'connection' => 'database',
        'queue' => 'high',
        'payload' => json_encode([
            'displayName' => 'App\\Jobs\\NotifyJob',
            'maxTries' => 1,
            'timeout' => 30,
        ]),
        'exception' => "RuntimeException: Something went wrong\nstack trace here",
        'failed_at' => now()->toDateTimeString(),
    ]);

    // Point the failer at the readonly connection's failed_jobs table.
    config()->set('queue.failed.driver', 'database-uuids');
    config()->set('queue.failed.database', 'readonly');
    config()->set('queue.failed.table', 'failed_jobs');
});

// --- tool-enabled gate ---

it('denies the call when queue_failed_jobs is disabled in config', function (): void {
    config()->set('agent-mcp.tools.queue_failed_jobs', false);

    QueueFailedJobsStubServer::tool(QueueFailedJobsTool::class, [])
        ->assertHasErrors();
});

// --- summary mode (default) ---

it('returns a summary grouped by queue and connection', function (): void {
    $response = QueueFailedJobsStubServer::tool(QueueFailedJobsTool::class, ['summary' => true])
        ->assertOk();

    $response->assertSee('default');
    $response->assertSee('high');
    $response->assertSee('database');
});

// --- list mode ---

it('returns a list of failed jobs with job_class and exception_summary', function (): void {
    $response = QueueFailedJobsStubServer::tool(QueueFailedJobsTool::class, [])
        ->assertOk();

    // The JSON output encodes backslashes as \\, so App\Jobs\ImportJob appears as App\\Jobs\\ImportJob.
    $response->assertSee('App\\\\Jobs\\\\ImportJob');
    $response->assertSee('Illuminate\\\\Database\\\\QueryException');
});

// --- raw payload NEVER emitted ---

it('does not emit the raw payload or the secret token in the payload', function (): void {
    $response = QueueFailedJobsStubServer::tool(QueueFailedJobsTool::class, [])
        ->assertOk();

    // The secret token embedded in the payload must never appear in the output.
    $response->assertDontSee(PAYLOAD_SECRET);

    // The full raw payload key must not appear.
    $response->assertDontSee('secret_data');
    $response->assertDontSee('raw_input');
});

// --- only first exception line emitted ---

it('emits only the first line of the exception', function (): void {
    $response = QueueFailedJobsStubServer::tool(QueueFailedJobsTool::class, [])
        ->assertOk();

    // The first line should appear (JSON-encoded backslashes).
    $response->assertSee('Illuminate\\\\Database\\\\QueryException');

    // Subsequent lines of the stack trace must not appear.
    $response->assertDontSee('more lines here');
});

// --- job_class from decoded payload ---

it('extracts the job class from the decoded payload displayName', function (): void {
    $response = QueueFailedJobsStubServer::tool(QueueFailedJobsTool::class, [])
        ->assertOk();

    // JSON encodes backslashes as \\, so App\Jobs\ImportJob appears as App\\Jobs\\ImportJob.
    $response->assertSee('App\\\\Jobs\\\\ImportJob');
    $response->assertSee('App\\\\Jobs\\\\NotifyJob');
});
