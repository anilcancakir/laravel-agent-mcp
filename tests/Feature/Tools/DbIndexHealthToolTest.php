<?php

use Anilcancakir\LaravelAgentMcp\Tools\DbIndexHealthTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only DbIndexHealthTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts DbIndexHealthTool for this test file only.
 */
final class DbIndexHealthStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbIndexHealthTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.db_index_health', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build the fixture schema on the readonly connection BEFORE the tool hardens
    // it (ReadonlyConnectionResolver sets PRAGMA query_only=ON on first access).
    Schema::connection('readonly')->dropIfExists('index_health_posts');
    Schema::connection('readonly')->create('index_health_posts', function (Blueprint $table): void {
        $table->id();
        $table->string('title')->index();
        $table->string('slug')->unique();
        $table->timestamps();
    });
});

// --- tool-enabled gate ---

it('denies the call when the tool is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_index_health', false);

    DbIndexHealthStubServer::tool(DbIndexHealthTool::class, [])
        ->assertHasErrors();
});

// --- SQLite path: index list ---

it('returns the index list for a known table on SQLite', function (): void {
    $response = DbIndexHealthStubServer::tool(DbIndexHealthTool::class, ['table' => 'index_health_posts'])
        ->assertOk();

    // The driver is reported so callers can branch on engine.
    $response->assertSee('sqlite');

    // The unique index on slug must be reflected in the index list.
    $response->assertSee('unique');
});

it('returns index data for every table when no table argument is given on SQLite', function (): void {
    DbIndexHealthStubServer::tool(DbIndexHealthTool::class, [])
        ->assertOk()
        ->assertSee('index_health_posts');
});

// --- unknown table: clean error ---

it('returns a clean error for an unknown table without leaking driver exceptions', function (): void {
    DbIndexHealthStubServer::tool(DbIndexHealthTool::class, ['table' => 'nonexistent_table'])
        ->assertHasErrors();
});

// --- binding safety: a crafted table name cannot alter query structure ---

it('treats a crafted table name as a bound literal, never query structure', function (): void {
    // A crafted name carrying SQL metacharacters and a stacked-statement attempt.
    // It is not a known table, so the tool rejects it cleanly; the fixture table
    // survives, proving the injected DROP never executed.
    $crafted = 'index_health_posts"; DROP TABLE index_health_posts; --';

    DbIndexHealthStubServer::tool(DbIndexHealthTool::class, ['table' => $crafted])
        ->assertHasErrors();

    expect(Schema::connection('readonly')->hasTable('index_health_posts'))->toBeTrue();
});

// --- PG/MySQL engine-gated coverage (suite runs on SQLite) ---

it('exercises the PostgreSQL unused-index path when the engine is PostgreSQL', function (): void {
    if (DB::connection('readonly')->getDriverName() !== 'pgsql') {
        expect(true)->toBeTrue();

        return;
    }

    DbIndexHealthStubServer::tool(DbIndexHealthTool::class, [])
        ->assertOk()
        ->assertSee('pgsql');
})->skip(
    fn (): bool => DB::connection('readonly')->getDriverName() !== 'pgsql',
    'PostgreSQL-only path; the suite runs on SQLite.',
);

it('exercises the MySQL STATISTICS path when the engine is MySQL', function (): void {
    DbIndexHealthStubServer::tool(DbIndexHealthTool::class, [])
        ->assertOk()
        ->assertSee('mysql');
})->skip(
    fn (): bool => DB::connection('readonly')->getDriverName() !== 'mysql',
    'MySQL-only path; the suite runs on SQLite.',
);
