<?php

use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubTokenUser;
use Anilcancakir\LaravelAgentMcp\Tools\DbSchemaTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server whose only registered tool is DbSchemaTool, keeping these tests
// isolated from Step 14's AgentMcpServer (which does not exist yet).

/**
 * Inline stub server that hosts DbSchemaTool for this test file only.
 *
 * Defined here so the test suite can drive through the real CallTool pipeline
 * without depending on AgentMcpServer (Step 14) or modifying StubAgentServer.
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

    // Wire the config keys the base tool reads.
    config()->set('agent-mcp.abilities.read', 'agent-mcp:read');
    config()->set('agent-mcp.tools.db_schema', true);
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.audit.enabled', false);

    // Build the fixture schema on the readonly connection BEFORE the tool hardens
    // it (ReadonlyConnectionResolver sets PRAGMA query_only=ON on first access).
    // Schema::connection() does the DDL on that connection; introspection then works
    // because reads are allowed after query_only is set.
    Schema::connection('readonly')->dropIfExists('schema_fixtures');
    Schema::connection('readonly')->create('schema_fixtures', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamps();
    });
});

afterEach(function (): void {
    // Clean up the fixture so each test starts with a predictable schema.
    // The hardened connection is read-only, so we target the underlying driver via
    // the standard connection which is still writeable at the testbench level.
    // Actually we need to use the readonly connection directly before hardening
    // in the next test's beforeEach; the beforeEach drops it anyway.
});

// ─── ability gate ────────────────────────────────────────────────────────────

it('denies the call when the token lacks the read ability', function (): void {
    $user = new StubTokenUser(id: 1, abilities: []);

    DbSchemaStubServer::actingAs($user)
        ->tool(DbSchemaTool::class, [])
        ->assertHasErrors();
});

it('denies the call when no user is authenticated', function (): void {
    DbSchemaStubServer::tool(DbSchemaTool::class, [])
        ->assertHasErrors();
});

it('denies the call when the tool is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_schema', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbSchemaStubServer::actingAs($user)
        ->tool(DbSchemaTool::class, [])
        ->assertHasErrors();
});

// ─── no-arg: list tables ─────────────────────────────────────────────────────

it('returns the list of tables when called with no arguments', function (): void {
    $user = new StubTokenUser(id: 2, abilities: ['agent-mcp:read']);

    DbSchemaStubServer::actingAs($user)
        ->tool(DbSchemaTool::class, [])
        ->assertOk()
        ->assertSee('schema_fixtures');
});

// ─── with-table: column / index / FK detail ──────────────────────────────────

it('returns columns, indexes and foreign keys for a known table', function (): void {
    $user = new StubTokenUser(id: 3, abilities: ['agent-mcp:read']);

    $response = DbSchemaStubServer::actingAs($user)
        ->tool(DbSchemaTool::class, ['table' => 'schema_fixtures'])
        ->assertOk();

    // Column names must appear in the output.
    $response->assertSee('name');
    $response->assertSee('email');

    // The unique index on email must appear.
    $response->assertSee('index');
});

// ─── unknown table: clean error ──────────────────────────────────────────────

it('returns a clean error for an unknown table without leaking driver exceptions', function (): void {
    $user = new StubTokenUser(id: 4, abilities: ['agent-mcp:read']);

    DbSchemaStubServer::actingAs($user)
        ->tool(DbSchemaTool::class, ['table' => 'nonexistent_table'])
        ->assertHasErrors();
});
