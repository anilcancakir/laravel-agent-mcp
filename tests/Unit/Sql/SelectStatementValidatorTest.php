<?php

namespace Anilcancakir\LaravelAgentMcp\Tests\Unit\Sql;

use Anilcancakir\LaravelAgentMcp\Sql\SelectStatementValidator;
use Anilcancakir\LaravelAgentMcp\Sql\UnsafeQueryException;

it('accepts a single well-formed read-only SELECT', function (string $sql): void {
    $validator = new SelectStatementValidator;

    expect(fn () => $validator->validate($sql))->not->toThrow(UnsafeQueryException::class);
})->with([
    'simple select with limit' => 'SELECT id, name FROM users WHERE active = 1 LIMIT 10',
    'select with order and limit' => 'SELECT id, name FROM users ORDER BY id DESC LIMIT 25',
    'select with join' => 'SELECT u.id, p.title FROM users u JOIN posts p ON p.user_id = u.id LIMIT 5',
    'select with aggregate' => 'SELECT count(*) AS total FROM users',
    'postgres now() expression' => 'SELECT id FROM users WHERE created_at > now() LIMIT 10',
    'read-only cte' => 'WITH recent AS (SELECT id FROM users ORDER BY id DESC LIMIT 5) SELECT * FROM recent',
    // A single-quoted literal is data, not an identifier, even when it spells a
    // forbidden name. Rejecting this would break ordinary WHERE clauses.
    'single-quoted literal spelling a forbidden name' => "SELECT id FROM users WHERE name = 'copy' LIMIT 1",
]);

it('rejects an unsafe query', function (string $sql): void {
    $validator = new SelectStatementValidator;

    expect(fn () => $validator->validate($sql))->toThrow(UnsafeQueryException::class);
})->with([
    'stacked drop statement' => 'SELECT 1; DROP TABLE x',
    'sqlite load_extension' => "SELECT load_extension('x')",
    'mysql load_file' => "SELECT LOAD_FILE('/etc/passwd')",
    'postgres pg_read_file' => "SELECT pg_read_file('/etc/passwd')",
    'postgres large object import' => "SELECT lo_import('/etc/passwd')",
    'data-writing cte' => 'WITH t AS (DELETE FROM x RETURNING *) SELECT * FROM t',
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
    // Quoting an identifier must not exempt it from the forbidden-token scan.
    // The lexer types a backtick-quoted identifier as a symbol and a
    // double-quoted one as a string, and strips the quotes from the value, so a
    // scan keyed only on keyword and bare-identifier tokens never inspects them.
    // Double quoting is the standard identifier form in PostgreSQL and SQLite
    // resolves both, so these reach the same functions the bare forms do.
    'backtick-quoted load_extension' => 'SELECT `load_extension`(\'x\')',
    'double-quoted load_extension' => 'SELECT "load_extension"(\'x\')',
    'backtick-quoted pg_read_file' => 'SELECT `pg_read_file`(\'/etc/passwd\')',
    'double-quoted pg_read_file' => 'SELECT "pg_read_file"(\'/etc/passwd\')',
    'backtick-quoted LOAD_FILE' => 'SELECT `LOAD_FILE`(\'/etc/passwd\')',
    'double-quoted lo_import' => 'SELECT "lo_import"(\'/etc/passwd\')',
    'double-quoted dblink' => 'SELECT "dblink"(\'x\', \'y\')',
    // The lo_ prefix family goes through the same scan, so it is bypassed the
    // same way. lo_get is not in FORBIDDEN_TOKENS and is caught only by prefix.
    'backtick-quoted lo_ prefix member' => 'SELECT `lo_get`(1)',
]);

it('does not leak the offending sql in the exception message', function (): void {
    $validator = new SelectStatementValidator;

    $message = null;

    try {
        $validator->validate("SELECT pg_read_file('/etc/passwd')");
    } catch (UnsafeQueryException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toBeString()
        ->and(str_contains($message, '/etc/passwd'))->toBeFalse()
        ->and(str_contains($message, 'pg_read_file'))->toBeFalse();
});
