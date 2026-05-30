<?php

namespace Anilcancakir\LaravelAgentMcp\Sql;

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\WithStatement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

/**
 * Validates a raw SQL string as a SINGLE read-only SELECT.
 *
 * This is an ALLOWLIST grammar, not a keyword blocklist: it accepts only the
 * known-safe shape (one well-formed SELECT, or a CTE whose every part is a
 * SELECT) and rejects everything else. It is defense-in-depth layered on the
 * read-only connection (Oracle CRIT1/CRIT2), never the sole boundary.
 *
 * SPIKE (phpmyadmin/sql-parser ^5.10, run 2026-05-30 against the three
 * dialects this package supports):
 * - MySQL, PostgreSQL and SQLite read-only SELECT shapes (WHERE, JOIN, ORDER
 *   BY, LIMIT, aggregates, now(), read-only CTEs) all parse to a single
 *   SelectStatement (or a WithStatement wrapping SELECTs) with zero parser
 *   errors. No dialect gap surfaced for the read-only subset.
 * - The parser is MySQL-grammar-first, so file/side-effect primitives that are
 *   PostgreSQL/SQLite functions (pg_read_file, lo_import, load_extension) or
 *   MySQL functions (LOAD_FILE) parse cleanly as a valid SelectStatement; the
 *   statement-type check alone does NOT catch them. They are caught by the
 *   token-stream scan below, which inspects the PARSED tree (not the raw
 *   string), keeping this an allowlist-consistency check rather than a regex
 *   blocklist.
 * - INTO OUTFILE/DUMPFILE additionally raises a parser error and populates
 *   SelectStatement::$into; both signals are enforced (belt and suspenders).
 * - Any narrowing decision favors rejection: a shape that fails to parse
 *   cleanly is rejected, never loosened.
 */
final class SelectStatementValidator
{
    /**
     * Identifiers that reach files or side effects even from a SELECT context.
     *
     * These are the primitives a read-only SELECT must never contain. The list
     * is matched against the PARSED token stream, not the raw input, so it is
     * part of the accepted-shape definition rather than a string blocklist.
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_TOKENS = [
        'into',
        'outfile',
        'dumpfile',
        'attach',
        'pragma',
        'copy',
        'load_file',
        'load_extension',
        'pg_read_file',
        'pg_read_binary_file',
        'pg_ls_dir',
        'lo_import',
        'lo_export',
        'dblink',
    ];

    /**
     * Prefixes whose entire identifier family is forbidden.
     *
     * PostgreSQL large-object access exposes a `lo_*` function family; matching
     * the prefix closes the whole family without enumerating every member.
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_PREFIXES = [
        'lo_',
    ];

    /**
     * Assert the given SQL is a single read-only SELECT, or reject it.
     *
     * @throws UnsafeQueryException When the SQL deviates from the accepted shape.
     */
    public function validate(string $sql): void
    {
        // 1. A query must contain visible content to be a SELECT at all.
        if (trim($sql) === '') {
            throw UnsafeQueryException::notReadOnlySelect();
        }

        $parser = new Parser($sql);

        // 2. Any parser error means the shape is malformed or ambiguous; an
        //    allowlist rejects on the first sign of deviation rather than
        //    guessing intent (catches data-writing CTEs, INTO clauses, garbage).
        if ($parser->errors !== []) {
            throw UnsafeQueryException::notReadOnlySelect();
        }

        // 3. Exactly one statement: a trailing ';'-separated statement (stacked
        //    injection) parses as a second statement and is rejected here.
        if (count($parser->statements) !== 1) {
            throw UnsafeQueryException::notReadOnlySelect();
        }

        // 4. The single statement must be an allowed read-only shape.
        $this->assertReadOnlyStatement($parser->statements[0]);

        // 5. No file/side-effect primitive may appear anywhere in the parsed
        //    token stream, even inside an otherwise-valid SELECT.
        $this->assertNoForbiddenTokens($parser->list);
    }

    /**
     * Accept only a SELECT, or a CTE whose every part is itself a SELECT.
     *
     * @throws UnsafeQueryException
     */
    private function assertReadOnlyStatement(Statement $statement): void
    {
        if ($statement instanceof SelectStatement) {
            $this->assertNoInto($statement);

            return;
        }

        if ($statement instanceof WithStatement) {
            $this->assertReadOnlyCte($statement);

            return;
        }

        throw UnsafeQueryException::notReadOnlySelect();
    }

    /**
     * A SELECT carrying INTO OUTFILE/DUMPFILE writes to the filesystem.
     *
     * @throws UnsafeQueryException
     */
    private function assertNoInto(SelectStatement $statement): void
    {
        if ($statement->into !== null) {
            throw UnsafeQueryException::notReadOnlySelect();
        }
    }

    /**
     * Every CTE term and the final body must parse to a read-only SELECT.
     *
     * @throws UnsafeQueryException
     */
    private function assertReadOnlyCte(WithStatement $statement): void
    {
        foreach ($statement->withers as $wither) {
            $inner = $wither->statement;

            // A CTE term that failed to parse, or whose body is not a single
            // SELECT (e.g. a DELETE ... RETURNING), is not a read-only shape.
            if ($inner === null || $inner->errors !== [] || count($inner->statements) !== 1) {
                throw UnsafeQueryException::notReadOnlySelect();
            }

            $this->assertReadOnlyStatement($inner->statements[0]);
        }
    }

    /**
     * Scan the parsed token stream for forbidden file/side-effect identifiers.
     *
     * @throws UnsafeQueryException
     */
    private function assertNoForbiddenTokens(TokensList $tokens): void
    {
        foreach ($tokens->tokens as $token) {
            if (! $this->isIdentifierToken($token)) {
                continue;
            }

            $value = strtolower((string) $token->value);

            if (in_array($value, self::FORBIDDEN_TOKENS, true)) {
                throw UnsafeQueryException::notReadOnlySelect();
            }

            foreach (self::FORBIDDEN_PREFIXES as $prefix) {
                if (str_starts_with($value, $prefix)) {
                    throw UnsafeQueryException::notReadOnlySelect();
                }
            }
        }
    }

    /**
     * Only keyword and bare-identifier tokens can name a function or clause we
     * forbid; string literals and operators carry no executable identifier.
     */
    private function isIdentifierToken(Token $token): bool
    {
        return $token->type === Token::TYPE_KEYWORD
            || $token->type === Token::TYPE_NONE;
    }
}
