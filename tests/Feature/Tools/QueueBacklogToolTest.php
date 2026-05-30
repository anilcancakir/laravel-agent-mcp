<?php

use Anilcancakir\LaravelAgentMcp\Tools\QueueBacklogTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only QueueBacklogTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts QueueBacklogTool for this test file only.
 */
final class QueueBacklogStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        QueueBacklogTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.queue_backlog', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build the fixture jobs table on the readonly connection BEFORE the tool hardens
    // it (ReadonlyConnectionResolver sets PRAGMA query_only=ON on first access).
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

    // Seed a pending job on the "default" queue.
    DB::connection('readonly')->table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SomeJob']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subSecond()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    // Configure the database queue connection so the tool can resolve it.
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'connection' => 'readonly',
    ]);

    // Ensure sync driver is present (also a named sync_test entry for isolation in that test).
    config()->set('queue.connections.sync', ['driver' => 'sync']);
    config()->set('queue.connections.sync_test', ['driver' => 'sync']);
});

// --- tool-enabled gate ---

it('denies the call when queue_backlog is disabled in config', function (): void {
    config()->set('agent-mcp.tools.queue_backlog', false);

    QueueBacklogStubServer::tool(QueueBacklogTool::class, [])
        ->assertHasErrors();
});

// --- basic listing ---

it('returns queue backlog data for each connection', function (): void {
    $response = QueueBacklogStubServer::tool(QueueBacklogTool::class, [])
        ->assertOk();

    $response->assertSee('database');
});

// --- database driver strict-pending count ---

it('includes a strict_pending count for the database driver', function (): void {
    $response = QueueBacklogStubServer::tool(QueueBacklogTool::class, [])
        ->assertOk();

    $response->assertSee('strict_pending');
});

// --- queue name enumeration ---

it('enumerates queue names for the database driver', function (): void {
    $response = QueueBacklogStubServer::tool(QueueBacklogTool::class, [])
        ->assertOk();

    $response->assertSee('default');
});

// --- sync driver N/A note ---

it('marks the sync driver as not_applicable', function (): void {
    $response = QueueBacklogStubServer::tool(QueueBacklogTool::class, ['connection' => 'sync_test'])
        ->assertOk();

    $response->assertSee('not_applicable');
});

// --- connection scoping arg ---

it('scopes output to a single connection when the connection arg is given', function (): void {
    $response = QueueBacklogStubServer::tool(QueueBacklogTool::class, ['connection' => 'database'])
        ->assertOk();

    $response->assertSee('database');
});
