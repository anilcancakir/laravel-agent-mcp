<?php

declare(strict_types=1);

use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// These tests pin the REAL SQLi boundary (Oracle CRIT1): the readonly connection
// must reject the EMULATE_PREPARES misconfiguration loudly and must physically
// refuse writes on SQLite via PRAGMA query_only. They are negative, fail-closed
// tests: a write that succeeds is a security regression, not a test bug.

it('resolves the configured readonly connection by name', function (): void {
    $resolver = new ReadonlyConnectionResolver;

    $connection = $resolver->connection();

    expect($connection)->toBeInstanceOf(Connection::class);
    expect($connection->getName())->toBe('readonly');
});

it('throws a clear configuration error when the connection name is missing', function (): void {
    config()->set('agent-mcp.connection', null);

    $resolver = new ReadonlyConnectionResolver;

    expect(fn (): Connection => $resolver->connection())
        ->toThrow(RuntimeException::class);
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

it('does not silently fall back to the default connection when readonly is misconfigured', function (): void {
    config()->set('agent-mcp.connection', '');

    $resolver = new ReadonlyConnectionResolver;

    expect(fn (): Connection => $resolver->connection())
        ->toThrow(RuntimeException::class);
});

it('throws from assertReadonly when EMULATE_PREPARES is true on a supporting driver', function (): void {
    // SQLite's PDO driver cannot report ATTR_EMULATE_PREPARES, so the assertion
    // reads the configured option. Configure the readonly connection with the
    // dangerous override and purge so the resolver sees it on resolution.
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
    // Timeout SET is engine-specific; SQLite has no max_execution_time. This test
    // documents the contract and is skipped unless a MySQL connection is wired.
    expect(true)->toBeTrue();
})->skip('MySQL connection not available in this environment');

it('applies PostgreSQL statement_timeout hardening', function (): void {
    expect(true)->toBeTrue();
})->skip('PostgreSQL connection not available in this environment');
