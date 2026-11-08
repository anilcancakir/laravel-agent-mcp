<?php

use Anilcancakir\LaravelAgentMcp\Tools\MigrationsStatusTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// Inline stub server hosting only MigrationsStatusTool, isolated from AgentMcpServer.

/**
 * Inline stub server that hosts MigrationsStatusTool for this test file only.
 */
final class MigrationsStatusStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        MigrationsStatusTool::class,
    ];
}

beforeEach(function (): void {
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.migrations_status', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build a fixture migrations table on the readonly connection BEFORE hardening
    // (PRAGMA query_only=ON on first resolve).
    Schema::connection('readonly')->dropIfExists('migrations');
    Schema::connection('readonly')->create('migrations', function (Blueprint $table): void {
        $table->id();
        $table->string('migration');
        $table->integer('batch');
    });

    DB::connection('readonly')->table('migrations')->insert([
        ['migration' => '2024_01_01_000000_create_users_table', 'batch' => 1],
        ['migration' => '2024_02_02_000000_create_posts_table', 'batch' => 2],
    ]);
});

// --- tool-enabled gate ---

it('denies the call when migrations_status is disabled in config', function (): void {
    config()->set('agent-mcp.tools.migrations_status', false);

    MigrationsStatusStubServer::tool(MigrationsStatusTool::class, [])
        ->assertHasErrors();
});

// --- reads the ran migrations from the migrations table ---

it('returns the ran migrations with their batch numbers', function (): void {
    $response = MigrationsStatusStubServer::tool(MigrationsStatusTool::class, [])
        ->assertOk();

    $response->assertSee('2024_01_01_000000_create_users_table');
    $response->assertSee('2024_02_02_000000_create_posts_table');
    $response->assertSee('batch');
});

it('names each migration once, grouped by batch, in compact JSON', function (): void {
    $text = (string) app(MigrationsStatusTool::class)->handle(new Request([]))->content();
    $payload = json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);

    expect(substr_count($text, '2024_01_01_000000_create_users_table'))->toBe(1)
        ->and(substr_count($text, '2024_02_02_000000_create_posts_table'))->toBe(1)
        ->and($text)->not->toContain("\n")
        ->and($payload)->toHaveKeys(['ran_count', 'batches'])
        ->and(array_key_exists('ran', $payload))->toBeFalse();
});

it('is annotated read-only within the description budget', function (): void {
    $tool = app(MigrationsStatusTool::class);

    expect($tool->toArray()['annotations'])->toHaveKey('readOnlyHint', true)
        ->and(mb_strlen((string) $tool->description()))->toBeLessThanOrEqual(1500)
        ->and((string) $tool->description())->not->toContain('Read-only.');
});

// --- flags filesystem-required pending detection (never reads the FS) ---

it('flags pending detection as filesystem_required without reading the filesystem', function (): void {
    $response = MigrationsStatusStubServer::tool(MigrationsStatusTool::class, [])
        ->assertOk();

    $response->assertSee('pending_detection');
    $response->assertSee('filesystem_required');
});
