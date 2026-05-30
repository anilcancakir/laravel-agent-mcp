<?php

use Anilcancakir\LaravelAgentMcp\Tools\DbTableSizesTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// Inline stub server hosting only DbTableSizesTool, isolated from AgentMcpServer.

/**
 * Inline stub server that hosts DbTableSizesTool for this test file only.
 */
final class DbTableSizesStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbTableSizesTool::class,
    ];
}

beforeEach(function (): void {
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.db_table_sizes', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build the fixture on the readonly connection BEFORE hardening (PRAGMA
    // query_only=ON on first resolve).
    Schema::connection('readonly')->dropIfExists('size_fixtures');
    Schema::connection('readonly')->create('size_fixtures', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

// --- tool-enabled gate ---

it('denies the call when db_table_sizes is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_table_sizes', false);

    DbTableSizesStubServer::tool(DbTableSizesTool::class, [])
        ->assertHasErrors();
});

// --- SQLite path returns sizes ---

it('returns table size data on SQLite', function (): void {
    $response = DbTableSizesStubServer::tool(DbTableSizesTool::class, [])
        ->assertOk();

    $response->assertSee('size_fixtures');
    $response->assertSee('total_bytes');
});

// --- engine + measurement-source labelling ---

it('labels the engine and measurement source on SQLite', function (): void {
    $response = DbTableSizesStubServer::tool(DbTableSizesTool::class, [])
        ->assertOk();

    $response->assertSee('sqlite');
    $response->assertSee('source');
});

// --- table arg scoping ---

it('scopes output to a single known table when the table arg is given', function (): void {
    $response = DbTableSizesStubServer::tool(DbTableSizesTool::class, ['table' => 'size_fixtures'])
        ->assertOk();

    $response->assertSee('size_fixtures');
});

// --- unknown table: clean error ---

it('returns a clean error for an unknown table without leaking a driver exception', function (): void {
    DbTableSizesStubServer::tool(DbTableSizesTool::class, ['table' => 'nonexistent_table'])
        ->assertHasErrors();
});
