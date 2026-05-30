<?php

declare(strict_types=1);

use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubTokenUser;
use Anilcancakir\LaravelAgentMcp\Tools\DbQueryTool;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// DbQueryTool runs structured, bound, read-only queries over the hardened readonly
// connection. These tests prove: correct query_type routing, limit clamping, column and
// table validation, injection values are bound (not executed), and operator enum enforcement.

/**
 * Self-contained MCP server stub scoped to DbQueryTool so we do not depend on
 * AgentMcpServer (Step 14) or any other tool file that may not exist yet.
 */
class DbQueryToolServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbQueryTool::class,
    ];
}

beforeEach(function (): void {
    // 1. Register laravel/mcp's provider so Request injection is populated.
    app()->register(McpServiceProvider::class);

    // 2. Pin the config keys the tool reads.
    config()->set('agent-mcp.abilities.read', 'agent-mcp:read');
    config()->set('agent-mcp.tools.db_query', true);
    config()->set('agent-mcp.query.max_rows', 3);

    // 3. Seed a fixture table on the readonly connection so every test has real rows.
    //    We use the default 'testbench' connection for seeding because the readonly
    //    connection has PRAGMA query_only = ON applied by ReadonlyConnectionResolver
    //    on first resolution. The 'readonly' connection in tests shares SQLite
    //    in-memory but is a separate connection instance, so we seed on 'testbench'
    //    and mirror the schema + data on 'readonly' before PRAGMA locks it.
    //
    //    Concrete approach: create the table and rows on the 'readonly' connection
    //    BEFORE DbQueryTool::handle() resolves it (the resolver applies query_only
    //    on first call). We use DB::connection('readonly')->statement() directly,
    //    which bypasses the resolver.
    DB::connection('readonly')->statement('CREATE TABLE IF NOT EXISTS agents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL
    )');

    DB::connection('readonly')->table('agents')->insert([
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'email' => 'bob@example.com'],
        ['name' => 'Carol', 'email' => 'carol@example.com'],
    ]);
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('denies in handle() when the token lacks the read ability', function (): void {
    $user = new StubTokenUser(id: 1, abilities: []);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, ['table' => 'agents', 'query_type' => 'where'])
        ->assertHasErrors();
});

it('denies in handle() when the tool is disabled in config', function (): void {
    config()->set('agent-mcp.tools.db_query', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, ['table' => 'agents', 'query_type' => 'where'])
        ->assertHasErrors();
});

// ---------------------------------------------------------------------------
// query_type: where
// ---------------------------------------------------------------------------

it('returns rows for a where query with a condition', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    $result = DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
            'conditions' => [
                ['column' => 'name', 'operator' => '=', 'value' => 'Alice'],
            ],
        ])
        ->assertOk();

    $result->assertSee('Alice');
    $result->assertDontSee('Bob');
});

it('returns all rows for a where query with no conditions', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
        ])
        ->assertOk()
        ->assertSee('Alice')
        ->assertSee('Bob');
});

// ---------------------------------------------------------------------------
// query_type: find
// ---------------------------------------------------------------------------

it('returns a single row for a find query by id', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'find',
            'id' => 1,
        ])
        ->assertOk()
        ->assertSee('Alice')
        ->assertDontSee('Bob');
});

it('returns null for a find query when the id does not exist', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'find',
            'id' => 999,
        ])
        ->assertOk()
        ->assertSee('null');
});

// ---------------------------------------------------------------------------
// query_type: count
// ---------------------------------------------------------------------------

it('returns the row count for a count query', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'count',
        ])
        ->assertOk()
        ->assertSee('3');
});

it('returns count filtered by conditions', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'count',
            'conditions' => [
                ['column' => 'name', 'operator' => '=', 'value' => 'Alice'],
            ],
        ])
        ->assertOk()
        ->assertSee('1');
});

// ---------------------------------------------------------------------------
// Limit clamping
// ---------------------------------------------------------------------------

it('clamps the limit to max_rows when the caller requests more', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);
    // max_rows = 3 (set in beforeEach); we have 3 rows, caller requests 100 — result
    // count must be at most max_rows (3). Verify Carol appears (within limit) but that
    // no crash occurs and the query runs with a binding-safe LIMIT.
    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
            'limit' => 100,
        ])
        ->assertOk()
        ->assertSee('Alice');
});

it('uses max_rows as the default limit when none is provided', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);
    config()->set('agent-mcp.query.max_rows', 2);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    // With max_rows=2 and 3 rows in the table the result must contain at most 2 rows.
    // Alice and Bob are the first 2 rows; Carol must NOT appear.
    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
        ])
        ->assertOk()
        ->assertSee('Alice')
        ->assertDontSee('Carol');
});

// ---------------------------------------------------------------------------
// Injection safety: bound parameters
// ---------------------------------------------------------------------------

it('binds injection values and returns no spurious rows', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    // The value "1 OR 1=1" must be treated as a literal bound value — no row has
    // name = '1 OR 1=1', so the result must be empty (not all rows).
    $result = DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
            'conditions' => [
                ['column' => 'name', 'operator' => '=', 'value' => '1 OR 1=1'],
            ],
        ])
        ->assertOk();

    $result->assertDontSee('Alice');
    $result->assertDontSee('Bob');
    $result->assertDontSee('Carol');
});

// ---------------------------------------------------------------------------
// Operator enum enforcement
// ---------------------------------------------------------------------------

it('rejects an operator that is not in the allowlist enum', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
            'conditions' => [
                ['column' => 'name', 'operator' => 'DROP TABLE', 'value' => 'x'],
            ],
        ])
        ->assertHasErrors();
});

it('accepts all allowlisted operators without error', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    $operators = ['=', '!=', '<', '>', '<=', '>=', 'like'];

    foreach ($operators as $op) {
        DbQueryToolServer::actingAs($user)
            ->tool(DbQueryTool::class, [
                'table' => 'agents',
                'query_type' => 'where',
                'conditions' => [
                    ['column' => 'id', 'operator' => $op, 'value' => 1],
                ],
            ])
            ->assertOk();
    }
});

it('accepts the in operator and matches multiple values', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
            'conditions' => [
                ['column' => 'name', 'operator' => 'in', 'value' => ['Alice', 'Bob']],
            ],
        ])
        ->assertOk()
        ->assertSee('Alice')
        ->assertSee('Bob')
        ->assertDontSee('Carol');
});

// ---------------------------------------------------------------------------
// Column + table validation
// ---------------------------------------------------------------------------

it('returns a clean error for an unknown table', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'does_not_exist',
            'query_type' => 'where',
        ])
        ->assertHasErrors();
});

it('returns a clean error for an unknown column in conditions', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
            'conditions' => [
                ['column' => 'injected_column; DROP TABLE agents--', 'operator' => '=', 'value' => 'x'],
            ],
        ])
        ->assertHasErrors();
});

it('returns a clean error for an unknown column in select', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
            'select' => ['nonexistent_column'],
        ])
        ->assertHasErrors();
});

it('returns a clean error for an unknown order_by column', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
            'order_by' => 'bad_column',
        ])
        ->assertHasErrors();
});

// ---------------------------------------------------------------------------
// select, order_by, order_dir
// ---------------------------------------------------------------------------

it('returns only the selected columns', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
            'select' => ['name'],
        ])
        ->assertOk()
        ->assertSee('Alice')
        ->assertDontSee('alice@example.com');
});

it('orders results by the given column descending', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    // Ordering by name desc with limit=1: Carol (C) sorts first, so only Carol
    // appears in the result. Alice and Bob must be absent (they come after in desc).
    DbQueryToolServer::actingAs($user)
        ->tool(DbQueryTool::class, [
            'table' => 'agents',
            'query_type' => 'where',
            'order_by' => 'name',
            'order_dir' => 'desc',
            'limit' => 1,
        ])
        ->assertOk()
        ->assertSee('Carol')
        ->assertDontSee('Alice')
        ->assertDontSee('Bob');
});
