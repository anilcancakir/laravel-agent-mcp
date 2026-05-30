<?php

use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// These tests pin the REAL SQLi boundary (Oracle CRIT1): the readonly connection
// must reject the EMULATE_PREPARES misconfiguration loudly and must physically
// refuse writes on SQLite via PRAGMA query_only. They are negative, fail-closed
// tests: a write that succeeds is a security regression, not a test bug.

it('resolves the configured readonly connection by name', function (): void {
    // A dedicated connection name is configured: the resolver uses it directly
    // (the recommended setup with a dedicated readonly DB user).
    config()->set('agent-mcp.connection', 'readonly');

    $resolver = new ReadonlyConnectionResolver;

    $connection = $resolver->connection();

    expect($connection)->toBeInstanceOf(Connection::class);
    expect($connection->getName())->toBe('readonly');
});

it('falls back to a hardened ephemeral clone of the default when no connection is configured', function (): void {
    config()->set('agent-mcp.connection', null);

    $resolver = new ReadonlyConnectionResolver;

    $resolved = $resolver->connection();
    $default = DB::connection(config('database.default'));

    // The fallback must NOT reuse the shared default instance: hardening it in place
    // (PRAGMA query_only / read-only session) would leak to the app under Octane or
    // any persistent connection. A distinct ephemeral connection is the boundary.
    expect($resolved)->toBeInstanceOf(Connection::class);
    expect($resolved->getName())->toBe('agent-mcp-readonly');
    expect($resolved)->not->toBe($default);
});

it('does not leak query_only onto the shared default connection on fallback', function (): void {
    config()->set('agent-mcp.connection', null);

    $resolver = new ReadonlyConnectionResolver;

    $resolver->connection();

    // The shared default must remain writable: query_only must NOT have leaked onto it.
    $default = DB::connection(config('database.default'));
    $queryOnly = $default->selectOne('PRAGMA query_only');

    expect((int) $queryOnly->query_only)->toBe(0);
});

it('rejects a write through the ephemeral fallback connection (fail closed)', function (): void {
    config()->set('agent-mcp.connection', null);

    $resolver = new ReadonlyConnectionResolver;

    $connection = $resolver->connection();

    // The cloned ephemeral connection is hardened with PRAGMA query_only = ON, so a
    // write DDL is physically refused: "attempt to write a readonly database".
    expect(fn (): bool => $connection->statement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)'))
        ->toThrow(QueryException::class, 'readonly database');
});

it('falls back to the ephemeral clone when the configured connection is an empty string', function (): void {
    config()->set('agent-mcp.connection', '');

    $resolver = new ReadonlyConnectionResolver;

    $resolved = $resolver->connection();

    expect($resolved->getName())->toBe('agent-mcp-readonly');
    expect($resolved)->not->toBe(DB::connection(config('database.default')));
});

it('throws when the resolved connection does not exist', function (): void {
    config()->set('agent-mcp.connection', 'does-not-exist');

    $resolver = new ReadonlyConnectionResolver;

    expect(fn (): Connection => $resolver->connection())
        ->toThrow(RuntimeException::class);
});

it('enables PRAGMA query_only on the resolved SQLite connection', function (): void {
    $resolver = new ReadonlyConnectionResolver;

    $connection = $resolver->connection();

    $queryOnly = $connection->selectOne('PRAGMA query_only');

    expect((int) $queryOnly->query_only)->toBe(1);
});

it('rejects a write statement on the resolved readonly connection (fail closed)', function (): void {
    $resolver = new ReadonlyConnectionResolver;

    $connection = $resolver->connection();

    // A CREATE TABLE is a write DDL needing no pre-existing table. Under PRAGMA
    // query_only = ON it is refused with "attempt to write a readonly database",
    // which is the query_only boundary firing (a missing-table error would prove
    // nothing about read-only enforcement).
    expect(fn (): bool => $connection->statement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)'))
        ->toThrow(QueryException::class, 'readonly database');
});

it('throws from assertReadonly when EMULATE_PREPARES is true on a supporting driver', function (): void {
    // SQLite's PDO driver cannot report ATTR_EMULATE_PREPARES, so the assertion
    // reads the configured option. Point at the dedicated readonly connection,
    // configure it with the dangerous override, and purge so the resolver sees it
    // on resolution.
    config()->set('agent-mcp.connection', 'readonly');
    config()->set('database.connections.readonly.options', [
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);

    DB::purge('readonly');

    $resolver = new ReadonlyConnectionResolver;

    expect(function () use ($resolver): void {
        $resolver->assertReadonly();
    })->toThrow(RuntimeException::class, 'EMULATE_PREPARES');
});

it('passes assertReadonly when EMULATE_PREPARES is not enabled', function (): void {
    $resolver = new ReadonlyConnectionResolver;

    $resolver->assertReadonly();
})->throwsNoExceptions();

it('applies MySQL session statement timeout hardening', function (): void {
    // On MySQL the resolver issues SET SESSION max_execution_time = <ms>. MySQL has
    // no per-session read-only for a normal user, so the code layer (SELECT validator
    // + query builder) is the write boundary and a readonly GRANT is recommended.
    // SQLite has no max_execution_time, so this contract test is skipped unless a
    // MySQL connection is wired.
    expect(true)->toBeTrue();
})->skip('MySQL connection not available in this environment');

it('applies PostgreSQL read-only session and statement_timeout hardening', function (): void {
    // On PostgreSQL the resolver issues SET default_transaction_read_only = on (the
    // session itself refuses writes) AND SET statement_timeout = <ms>. Skipped unless
    // a PostgreSQL connection is wired.
    expect(true)->toBeTrue();
})->skip('PostgreSQL connection not available in this environment');
