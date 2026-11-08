<?php

use Anilcancakir\LaravelAgentMcp\Tools\QueueStatusTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

/**
 * Inline stub server that hosts QueueStatusTool for this test file only.
 */
final class QueueStatusStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        QueueStatusTool::class,
    ];
}

/**
 * Seed extra failed jobs on the readonly connection, before the tool hardens it.
 */
function seedQueueStatusFailedJobs(int $count): void
{
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'uuid' => "bulk-{$i}",
            'connection' => 'database',
            'queue' => 'bulk',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\BulkJob']),
            'exception' => "RuntimeException: bulk failure {$i}\nstack trace here",
            'failed_at' => now()->toDateTimeString(),
        ];
    }

    DB::connection('readonly')->table('failed_jobs')->insert($rows);
}

/**
 * Call the tool directly and decode its JSON text.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function queueStatusCall(array $arguments): array
{
    $response = app(QueueStatusTool::class)->handle(new Request($arguments));

    expect($response->isError())->toBeFalse();

    return json_decode((string) $response->content(), associative: true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.queue_status', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build the fixture tables on the readonly connection BEFORE the tool hardens it
    // (ReadonlyConnectionResolver sets PRAGMA query_only=ON on first access).
    Schema::connection('readonly')->dropIfExists('jobs');
    Schema::connection('readonly')->create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    DB::connection('readonly')->table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SomeJob']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subSecond()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'connection' => 'readonly',
    ]);
    config()->set('queue.connections.sync', ['driver' => 'sync']);

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

    DB::connection('readonly')->table('failed_jobs')->insert([
        [
            'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\ImportJob',
                'maxTries' => 3,
                'timeout' => 60,
                'secret_data' => 'SUPER_SECRET_PAYLOAD_TOKEN_abc123',
            ]),
            'exception' => "Illuminate\\Database\\QueryException: SQLSTATE[42S02]\nmore lines here",
            'failed_at' => now()->toDateTimeString(),
        ],
        [
            'uuid' => 'ffffffff-0000-1111-2222-333333333333',
            'connection' => 'database',
            'queue' => 'high',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\NotifyJob', 'maxTries' => 1, 'timeout' => 30]),
            'exception' => "RuntimeException: Something went wrong\nstack trace here",
            'failed_at' => now()->toDateTimeString(),
        ],
    ]);

    config()->set('queue.failed.driver', 'database-uuids');
    config()->set('queue.failed.database', 'readonly');
    config()->set('queue.failed.table', 'failed_jobs');
});

// --- tool-enabled gate ---

it('denies the call when queue_status is disabled in config', function (): void {
    config()->set('agent-mcp.tools.queue_status', false);

    QueueStatusStubServer::tool(QueueStatusTool::class, [])
        ->assertHasErrors();
});

it('is annotated read-only', function (): void {
    expect(app(QueueStatusTool::class)->toArray()['annotations'])
        ->toHaveKey('readOnlyHint', true);
});

it('keeps the description within the 1,500 character budget', function (): void {
    expect(mb_strlen((string) app(QueueStatusTool::class)->description()))->toBeLessThanOrEqual(1500);
});

// --- section: backlog (default) ---

it('reports the backlog per connection and queue when no section is given', function (): void {
    $payload = queueStatusCall([]);

    expect($payload['database']['driver'])->toBe('database')
        ->and($payload['database']['queues']['default'])->toHaveKeys(['size', 'strict_pending'])
        ->and($payload['database']['queues']['default']['strict_pending'])->toBe(1);
});

it('reports a note instead of a size for the sync driver', function (): void {
    $payload = queueStatusCall(['section' => 'backlog', 'connection' => 'sync']);

    expect($payload)->toBe(['sync' => ['note' => 'not_applicable']]);
});

it('scopes the backlog to a single connection', function (): void {
    $payload = queueStatusCall(['section' => 'backlog', 'connection' => 'database']);

    expect(array_keys($payload))->toBe(['database']);
});

// --- section: failed ---

it('returns the failed summary grouped by queue and connection', function (): void {
    $payload = queueStatusCall(['section' => 'failed', 'summary' => true]);

    expect($payload['summary'])->toEqualCanonicalizing([
        ['connection' => 'database', 'queue' => 'default', 'count' => 1],
        ['connection' => 'database', 'queue' => 'high', 'count' => 1],
    ]);
});

it('lists failed jobs without the raw payload or later exception lines', function (): void {
    $response = QueueStatusStubServer::tool(QueueStatusTool::class, ['section' => 'failed'])
        ->assertOk()
        ->assertSee('App\\\\Jobs\\\\ImportJob')
        ->assertSee('Illuminate\\\\Database\\\\QueryException')
        ->assertDontSee('SUPER_SECRET_PAYLOAD_TOKEN_abc123')
        ->assertDontSee('secret_data')
        ->assertDontSee('more lines here');

    $response->assertSee('"total":2');
});

it('leaves truncated out when the failed list fits the cap', function (): void {
    $payload = queueStatusCall(['section' => 'failed']);

    expect($payload['jobs'])->toHaveCount(2)
        ->and($payload['total'])->toBe(2)
        ->and($payload)->not->toHaveKey('truncated');
});

it('caps the failed list at 25 rows by default and reports total and truncated', function (): void {
    seedQueueStatusFailedJobs(28);

    $payload = queueStatusCall(['section' => 'failed']);

    expect($payload['jobs'])->toHaveCount(25)
        ->and($payload['total'])->toBe(30)
        ->and($payload['truncated'])->toBeTrue();
});

it('honours an explicit limit and floors it at one row', function (): void {
    seedQueueStatusFailedJobs(8);

    expect(queueStatusCall(['section' => 'failed', 'limit' => 5])['jobs'])->toHaveCount(5)
        ->and(queueStatusCall(['section' => 'failed', 'limit' => 0])['jobs'])->toHaveCount(1);
});

it('clamps the limit to 100 rows', function (): void {
    seedQueueStatusFailedJobs(120);

    $payload = queueStatusCall(['section' => 'failed', 'limit' => 1000]);

    expect($payload['jobs'])->toHaveCount(100)
        ->and($payload['total'])->toBe(122)
        ->and($payload['truncated'])->toBeTrue();
});

// --- section: horizon ---

it('returns available false when Horizon is not deployed', function (): void {
    QueueStatusStubServer::tool(QueueStatusTool::class, ['section' => 'horizon'])
        ->assertOk()
        ->assertSee('"available":false')
        ->assertSee('horizon not installed');
});

// --- section validation ---

it('rejects an unknown section with a message naming the valid values', function (): void {
    QueueStatusStubServer::tool(QueueStatusTool::class, ['section' => 'bogus'])
        ->assertHasErrors()
        ->assertSee('backlog')
        ->assertSee('failed')
        ->assertSee('horizon')
        ->assertDontSee('bogus');
});
