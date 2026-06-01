<?php

use Anilcancakir\LaravelAgentMcp\Tools\DbSchemaTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server whose only registered tool is DbSchemaTool, keeping these tests
// isolated from the full AgentMcpServer.

/**
 * Inline stub server that hosts DbSchemaTool for this test file only.
 *
 * Defined here so the test suite can drive through the real CallTool pipeline
 * without depending on AgentMcpServer or modifying StubAgentServer.
 */
final class DbSchemaStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbSchemaTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's own provider must be registered so the injected Request is
    // populated via the resolving(Request::class) callback (method-injection
    // contract). In production this is auto-discovered; here we do it explicitly.
    app()->register(McpServiceProvider::class);

    // Wire the config keys the base tool reads. Authentication is the HTTP layer's job
    // (the server-admin key); at the tool the only gate left is the tool-enabled flag.
    config()->set('agent-mcp.tools.db_schema', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build the fixture schema on the readonly connection BEFORE the tool hardens
    // it (ReadonlyConnectionResolver sets PRAGMA query_only=ON on first access).
    Schema::connection('readonly')->dropIfExists('schema_fixtures');
    Schema::connection('readonly')->create('schema_fixtures', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamps();
    });
});

// --- tool-enabled gate ---

it('denies the call when the tool is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_schema', false);

    DbSchemaStubServer::tool(DbSchemaTool::class, [])
        ->assertHasErrors();
});

// --- no-arg: list tables ---

it('returns the list of tables when called with no arguments', function (): void {
    DbSchemaStubServer::tool(DbSchemaTool::class, [])
        ->assertOk()
        ->assertSee('schema_fixtures');
});

// --- with-table: column / index / FK detail ---

it('returns columns, indexes and foreign keys for a known table', function (): void {
    $response = DbSchemaStubServer::tool(DbSchemaTool::class, ['table' => 'schema_fixtures'])
        ->assertOk();

    // Column names must appear in the output.
    $response->assertSee('name');
    $response->assertSee('email');

    // The unique index on email must appear.
    $response->assertSee('index');
});

// --- unknown table: clean error ---

it('returns a clean error for an unknown table without leaking driver exceptions', function (): void {
    DbSchemaStubServer::tool(DbSchemaTool::class, ['table' => 'nonexistent_table'])
        ->assertHasErrors();
});
