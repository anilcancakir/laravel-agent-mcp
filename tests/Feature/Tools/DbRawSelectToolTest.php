<?php

use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Anilcancakir\LaravelAgentMcp\Tools\DbRawSelectTool;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

/**
 * Minimal MCP server that registers only db_raw_select, so the laravel/mcp testing
 * API can route tools/call to it through the real CallTool pipeline.
 */
class DbRawSelectTestServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbRawSelectTool::class,
    ];
}

// db_raw_select is the highest-risk tool: it accepts raw SQL. These tests prove the
// load-bearing order (VALIDATE before EXECUTE) end to end: every malicious shape is
// rejected with a clean JSON-RPC error and NEVER reaches select(); a safe SELECT is
// bounded by an auto-appended LIMIT; the tool-enabled gate denies a disabled tool; and
// the belt-and-suspenders guarantee that the readonly connection physically refuses a
// write even if validation were bypassed. Authentication is the HTTP layer's job (the
// server-admin key); at the tool the only access gate left is the tool-enabled flag.

beforeEach(function (): void {
    // laravel/mcp's own provider populates the injected Request from the bound
    // mcp.request; register it here to exercise the real method-injection contract.
    app()->register(McpServiceProvider::class);

    // Pin the dedicated readonly connection so the tool resolves the same in-memory
    // database this test seeds (a null connection would resolve the ephemeral clone of
    // the default connection instead).
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('agent-mcp.tools.db_raw_select', true);
    config()->set('agent-mcp.query.max_rows', 100);
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', false);
});

/**
 * Seed the readonly SQLite fixture. MUST run before the tool's first $this->readonly()
 * call, because the resolver applies PRAGMA query_only = ON on first resolution and a
 * seed write would then be refused.
 */
function seedReadonlyUsers(): void
{
    $connection = DB::connection('readonly');

    $connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');

    for ($i = 1; $i <= 5; $i++) {
        $connection->insert(
            'INSERT INTO users (name, email) VALUES (?, ?)',
            ["user{$i}", "user{$i}@example.com"],
        );
    }
}

it('returns rows for a safe SELECT and enforces an auto-appended LIMIT', function (): void {
    seedReadonlyUsers();

    config()->set('agent-mcp.query.max_rows', 2);

    $response = DbRawSelectTestServer::tool(DbRawSelectTool::class, ['sql' => 'SELECT id, name FROM users'])
        ->assertOk();

    // max_rows = 2 was appended as a LIMIT, so the unbounded SELECT returns exactly 2.
    $response->assertSee('user1')
        ->assertSee('user2')
        ->assertDontSee('user3');
});

it('preserves an explicit LIMIT instead of appending another', function (): void {
    seedReadonlyUsers();

    config()->set('agent-mcp.query.max_rows', 100);

    DbRawSelectTestServer::tool(DbRawSelectTool::class, ['sql' => 'SELECT id, name FROM users LIMIT 3'])
        ->assertOk()
        ->assertSee('user3')
        ->assertDontSee('user4');
});

it('wraps the rows and marks the result truncated only when the cap cut it', function (): void {
    seedReadonlyUsers();

    // Five seeded rows against a cap of four: the fifth row proves the cut.
    config()->set('agent-mcp.query.max_rows', 4);

    DbRawSelectTestServer::tool(DbRawSelectTool::class, ['sql' => 'SELECT id, name FROM users ORDER BY id'])
        ->assertOk()
        ->assertSee(['"rows":', 'user4', '"truncated":true'])
        ->assertDontSee('user5');

    // Exactly the cap: nothing was cut, so no marker.
    config()->set('agent-mcp.query.max_rows', 5);

    DbRawSelectTestServer::tool(DbRawSelectTool::class, ['sql' => 'SELECT id, name FROM users ORDER BY id'])
        ->assertOk()
        ->assertSee(['"rows":', 'user5'])
        ->assertDontSee('truncated');
});

it('puts the appended cap on its own line so a trailing comment cannot swallow it', function (string $sql): void {
    seedReadonlyUsers();

    config()->set('agent-mcp.query.max_rows', 2);

    DbRawSelectTestServer::tool(DbRawSelectTool::class, ['sql' => $sql])
        ->assertOk()
        ->assertSee(['user2', '"truncated":true'])
        ->assertDontSee('user3');
})->with([
    // SQLite has no '#' comment, so the MySQL spelling cannot run on this fixture;
    // both shapes share the newline that defeats it.
    'trailing comment' => 'SELECT id, name FROM users ORDER BY id -- x',
    'terminator then comment' => 'SELECT id, name FROM users ORDER BY id; -- x',
]);

it('caps an unbounded CTE', function (): void {
    seedReadonlyUsers();

    config()->set('agent-mcp.query.max_rows', 2);

    DbRawSelectTestServer::tool(DbRawSelectTool::class, [
        'sql' => 'WITH r AS (SELECT id, name FROM users) SELECT * FROM r ORDER BY id',
    ])
        ->assertOk()
        ->assertSee(['user2', '"truncated":true'])
        ->assertDontSee('user3');
});

it('keeps the explicit LIMIT of a CTE instead of appending a second one', function (): void {
    seedReadonlyUsers();

    // Only the tool's own query: the resolver also runs its hardening PRAGMA.
    $executed = [];
    DB::connection('readonly')->listen(function (QueryExecuted $query) use (&$executed): void {
        if (str_starts_with($query->sql, 'WITH')) {
            $executed[] = $query->sql;
        }
    });

    DbRawSelectTestServer::tool(DbRawSelectTool::class, [
        'sql' => 'WITH r AS (SELECT id, name FROM users) SELECT * FROM r ORDER BY id LIMIT 3',
    ])
        ->assertOk()
        ->assertSee('user3')
        ->assertDontSee(['user4', 'truncated']);

    expect($executed)->toHaveCount(1)
        ->and(substr_count(strtoupper($executed[0]), 'LIMIT'))->toBe(1);
});

it('refuses an explicit LIMIT above the cap or not a plain number, naming the cap', function (string $sql): void {
    seedReadonlyUsers();

    config()->set('agent-mcp.query.max_rows', 100);

    $reached = false;
    DB::connection('readonly')->listen(function () use (&$reached): void {
        $reached = true;
    });

    DbRawSelectTestServer::tool(DbRawSelectTool::class, ['sql' => $sql])
        ->assertHasErrors([
            'agent-mcp.query.max_rows',
            '100',
        ]);

    expect($reached)->toBeFalse();
})->with([
    'limit above the cap' => 'SELECT id FROM users LIMIT 100000',
    'offset form with a row count above the cap' => 'SELECT id FROM users LIMIT 5, 100000000',
    'placeholder row count' => 'SELECT id FROM users LIMIT ?',
    'cte limit above the cap' => 'WITH r AS (SELECT id FROM users) SELECT * FROM r LIMIT 1000',
]);

it('is annotated read-only', function (): void {
    expect(app(DbRawSelectTool::class)->toArray()['annotations'])
        ->toHaveKey('readOnlyHint', true);
});

it('rejects every malicious input from the validator matrix with a clean error', function (string $sql): void {
    seedReadonlyUsers();

    $response = DbRawSelectTestServer::tool(DbRawSelectTool::class, ['sql' => $sql])
        ->assertHasErrors();

    // The clean rejection must never echo the offending SQL or driver internals back.
    $response->assertDontSee('/etc/passwd')
        ->assertDontSee('DROP')
        ->assertDontSee('SQLSTATE');
})->with([
    'stacked drop statement' => 'SELECT 1; DROP TABLE users',
    'sqlite load_extension' => "SELECT load_extension('x')",
    'mysql load_file' => "SELECT LOAD_FILE('/etc/passwd')",
    'postgres pg_read_file' => "SELECT pg_read_file('/etc/passwd')",
    'postgres large object import' => "SELECT lo_import('/etc/passwd')",
    'data-writing cte' => 'WITH t AS (DELETE FROM users RETURNING *) SELECT * FROM t',
    'into outfile' => "SELECT * FROM users INTO OUTFILE '/tmp/x'",
    'into dumpfile' => "SELECT * FROM users INTO DUMPFILE '/tmp/x'",
    'attach database' => "ATTACH DATABASE 'x' AS y",
    'pragma' => 'PRAGMA table_info(users)',
    'copy to file' => "COPY users TO '/tmp/x'",
    'non-select update' => "UPDATE users SET name = 'x' WHERE id = 1",
    'non-select insert' => "INSERT INTO users (name) VALUES ('x')",
    'unparseable garbage' => 'NOT SQL AT ALL (((',
    'empty string' => '',
    'whitespace only' => '   ',
    // Quoted, escaped and wrapped spellings of the same primitives. The unit
    // corpus covers the family; these prove the tool path refuses them too,
    // since a correct validator says nothing about db_raw_select calling it.
    'backtick-quoted pg_read_file' => 'SELECT `pg_read_file`(\'/etc/passwd\')',
    'double-quoted pg_read_file' => 'SELECT "pg_read_file"(\'/etc/passwd\')',
    'unicode-escape pg_read_file' => 'SELECT U&"pg_\0072ead_file"(\'/etc/passwd\')',
    'dblink_exec on a second connection' => "SELECT dblink_exec('dbname=x', 'DROP TABLE users')",
    'query_to_xml carrying a forbidden call' => "SELECT query_to_xml('SELECT pg_read_file(''/etc/passwd'')', true, true, '')",
]);

it('never reaches select() when validation rejects the input', function (): void {
    seedReadonlyUsers();

    // A spy on the readonly connection's select(): a rejected input must short-circuit
    // before any query runs.
    $reached = false;
    DB::connection('readonly')->listen(function () use (&$reached): void {
        $reached = true;
    });

    DbRawSelectTestServer::tool(DbRawSelectTool::class, ['sql' => 'SELECT 1; DROP TABLE users'])
        ->assertHasErrors();

    expect($reached)->toBeFalse();
});

it('denies when the tool is disabled in config', function (): void {
    seedReadonlyUsers();

    config()->set('agent-mcp.tools.db_raw_select', false);

    DbRawSelectTestServer::tool(DbRawSelectTool::class, ['sql' => 'SELECT id FROM users'])
        ->assertHasErrors();
});

it('refuses a write on the readonly connection even if validation were bypassed (belt and suspenders)', function (): void {
    seedReadonlyUsers();

    // Resolve the SAME readonly connection the tool uses and harden it as the tool would
    // (the resolver applies PRAGMA query_only = ON). A write must be refused at this layer.
    $resolver = new ReadonlyConnectionResolver;
    $connection = $resolver->connection();

    expect(fn (): bool => $connection->statement('INSERT INTO users (name) VALUES (\'mallory\')'))
        ->toThrow(QueryException::class, 'readonly database');
});
