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
    // Quoted identifiers that name nothing forbidden must still pass. These are
    // the guards on the scan's admission set: narrowing which token types reach
    // the scan would leave every rejection case green while reopening the hole,
    // and narrowing what the scan accepts would break these instead.
    'double-quoted ordinary identifiers' => 'SELECT "name" FROM "users" LIMIT 1',
    'backtick-quoted ordinary identifiers' => 'SELECT `name` FROM `users` LIMIT 1',
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
    // PostgreSQL unicode-escape identifiers. The lexer runs stripcslashes over
    // the quoted body, so \0072 becomes "2" and the scan is handed
    // pg_2ead_file, while PostgreSQL unescapes \0072 to "r" and resolves
    // pg_read_file. The scan and the database disagree about what the name is,
    // so the name is one the scan cannot vouch for.
    'unicode-escape identifier' => 'SELECT U&"pg_\0072ead_file"(\'/etc/passwd\')',
    'unicode-escape identifier, lowercase prefix' => 'SELECT u&"pg_\0072ead_file"(\'/etc/passwd\')',
    'unicode-escape identifier, six digit form' => 'SELECT U&"pg_\+000072ead_file"(\'/etc/passwd\')',
    // Rejected today only because UESCAPE raises a parser error. Pinned so the
    // behaviour stops being incidental.
    'unicode-escape identifier with UESCAPE' => 'SELECT U&"pg_!0072ead_file"(\'/etc/passwd\') UESCAPE \'!\'',
    // A double-quoted identifier is only trustworthy when unescaping it changed
    // nothing. These two carry a backslash escape, so the scanned value is not
    // the name the database would resolve, whatever that name turns out to be.
    'backslash-escaped double-quoted identifier' => 'SELECT "pg\_read\_file"(\'/etc/passwd\')',
    'backslash-escaped harmless-looking identifier' => 'SELECT "lo\_get"(1)',
    // The dblink_ family. 'dblink' was an exact match only, so the members that
    // do the work walked past it. dblink_exec runs arbitrary SQL on a second
    // connection, which leaves the read-only session altogether.
    'dblink_exec' => "SELECT dblink_exec('dbname=x', 'DROP TABLE users')",
    'dblink_connect' => "SELECT dblink_connect('dbname=x')",
    // File and directory siblings of pg_ls_dir, which was listed alone.
    'postgres pg_stat_file' => "SELECT pg_stat_file('/etc/passwd')",
    'postgres pg_file_read' => "SELECT pg_file_read('/etc/passwd', 0, 100)",
    'postgres pg_ls_logdir' => 'SELECT pg_ls_logdir()',
    'postgres pg_ls_waldir' => 'SELECT pg_ls_waldir()',
    'postgres pg_ls_tmpdir' => 'SELECT pg_ls_tmpdir()',
    // Functions that EXECUTE a string argument. The payload sits in a
    // single-quoted literal, which the scan skips by design, so naming these
    // four closes these four and not the class they belong to.
    'query_to_xml carrying a forbidden call' => "SELECT query_to_xml('SELECT pg_read_file(''/etc/passwd'')', true, true, '')",
    'table_to_xml' => "SELECT table_to_xml('users', true, true, '')",
    'cursor_to_xml' => "SELECT cursor_to_xml('c', 10, true, true, '')",
    'query_to_xmlschema' => "SELECT query_to_xmlschema('SELECT 1', true, true, '')",
    // A quoted forbidden name is refused wherever it appears, not only in the
    // select list. These pin the position-independence of the scan.
    'quoted forbidden name in FROM position' => 'SELECT * FROM "pg_ls_dir" LIMIT 1',
    'quoted forbidden name inside a CTE body' => 'WITH t AS (SELECT `pg_read_file`(\'/etc/passwd\') AS c) SELECT * FROM t',
    // Deliberate false positives, pinned so nobody widens them back open.
    // Double-quoted text is an identifier in PostgreSQL and SQLite, which is
    // what the scan treats it as. On MySQL without ANSI_QUOTES it is a string
    // literal, so these two are refused even though a MySQL user would read
    // them as data. Single quotes are the portable spelling and still work,
    // which the accept dataset covers.
    'mysql double-quoted literal spelling a forbidden name' => 'SELECT id FROM users WHERE kind = "copy" LIMIT 1',
    'double-quoted column named after a forbidden primitive' => 'SELECT "copy" FROM articles LIMIT 1',
    // Statement types the shape allowlist refuses, with nothing proving it until
    // now, plus the forbidden names that were listed without a test.
    'bare truncate statement' => 'TRUNCATE TABLE users',
    'bare alter statement' => 'ALTER TABLE users ADD COLUMN x INT',
    'bare create statement' => 'CREATE TABLE evil (id INT)',
    'bare grant statement' => 'GRANT ALL ON users TO PUBLIC',
    'bare drop statement' => 'DROP TABLE users',
    'line-comment-smuggled statement' => "SELECT 1 -- \nDROP TABLE users",
    'block-comment-smuggled statement' => 'SELECT 1 /* comment */ DROP TABLE users',
    'postgres pg_read_binary_file' => "SELECT pg_read_binary_file('/etc/passwd')",
    'postgres pg_ls_dir' => "SELECT pg_ls_dir('/etc')",
    'postgres dblink' => "SELECT dblink('x', 'y')",
    // The dot operator splits pg_catalog. from pg_read_file, but the function
    // name still lands as a scanned identifier token, so this is rejected by
    // the same forbidden-token scan as the bare form, not by accident of parsing.
    'schema-qualified pg_read_file' => "SELECT pg_catalog.pg_read_file('/etc/passwd')",
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
