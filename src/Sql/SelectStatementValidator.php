<?php

namespace Anilcancakir\LaravelAgentMcp\Sql;

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\WithStatement;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;
use PhpMyAdmin\SqlParser\TokenType;

/**
 * Validates a raw SQL string as a SINGLE read-only SELECT.
 *
 * Two layers, and they are not the same kind of thing. Read this before trusting
 * either one.
 *
 * STATEMENT SHAPE is an allowlist: it accepts only one well-formed SELECT, or a
 * CTE whose every part is a SELECT, and rejects everything else. That layer is
 * complete, because it enumerates what is allowed.
 *
 * IDENTIFIER NAMES are a BLOCKLIST, and a knowingly incomplete one. FORBIDDEN_TOKENS
 * is a list of names, so it stops the primitives it happens to name and nothing
 * else. Three gaps have already been found in it by looking rather than by
 * reasoning: the dblink_ family walked past an exact match on 'dblink', the
 * pg_ls_dir siblings were absent, and query_to_xml executes a string argument the
 * scan cannot see into. Treat a new name in this list as evidence the list was
 * incomplete again, never as evidence it is now complete.
 *
 * For statement SHAPE this is defense-in-depth layered on the read-only
 * connection: a write statement is refused by both. For the file and
 * side-effect primitives below it is NOT. pg_read_file, pg_ls_dir, lo_import
 * and load_extension are reads as far as the database is concerned, so a
 * read-only session permits them and the token-stream scan is the only layer
 * standing there. Treat any change to that scan as a change to the boundary
 * itself, not as a refinement behind one.
 *
 * SPIKE (phpmyadmin/sql-parser ^6.0, run 2026-09-13 against the three
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
 *
 * KNOWN GAP, left open deliberately. A function that EXECUTES a string argument
 * is invisible to the name scan, because the payload sits in a single-quoted
 * literal and skipping those is what keeps `WHERE name = 'copy'` working. The
 * four xml functions above are named, so those four are closed; the shape is
 * not. `current_setting` is also accepted on purpose: it reads configuration
 * rather than the filesystem, the package already exposes that surface through
 * an opt-in tool, and blocking it would refuse a great deal of ordinary SQL.
 *
 * KNOWN FALSE POSITIVES, accepted deliberately. Double-quoted text is scanned as
 * an identifier, which is what PostgreSQL and SQLite make it. On MySQL without
 * ANSI_QUOTES it is a string literal instead, so `WHERE kind = "copy"` is refused
 * here while `WHERE kind = 'copy'` works. The same applies to a quoted column
 * named after a forbidden primitive. Single quotes are the portable spelling and
 * the one the tool documents; widening to fix these would reopen the identifier
 * bypass this scan exists to close.
 */
final class SelectStatementValidator
{
    /**
     * Identifiers that reach files or side effects even from a SELECT context.
     *
     * A BLOCKLIST of names, and incomplete by construction. Matching it against
     * the PARSED token stream rather than the raw input makes it harder to evade
     * by spelling, which is worth having, but it does not turn a list of names
     * into a definition of what is safe: a primitive nobody has written down
     * here passes. Adding a name is fixing one instance, not the class.
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
        'pg_file_read',
        'pg_stat_file',
        'pg_ls_dir',
        'pg_ls_logdir',
        'pg_ls_waldir',
        'pg_ls_tmpdir',
        'lo_import',
        'lo_export',
        'dblink',
        // These execute a string argument. Naming them closes these four and
        // NOT the class they belong to: the payload sits in a single-quoted
        // literal, which the scan skips by design, so any other function with
        // the same shape is still open. See the class docblock.
        'query_to_xml',
        'query_to_xmlschema',
        'table_to_xml',
        'cursor_to_xml',
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
        // dblink_exec runs arbitrary SQL on a second connection, which leaves
        // the read-only session altogether. 'dblink' as an exact match let every
        // member that does the work walk past it.
        'dblink_',
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
        //    token stream, even inside an otherwise-valid SELECT. An absent
        //    token list would skip the only layer standing in front of the file
        //    primitives, so it rejects rather than scanning nothing.
        if ($parser->list === null) {
            throw UnsafeQueryException::notReadOnlySelect();
        }

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

            $this->assertNameSurvivedUnescaping($token);

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
     * Reject a quoted name whose text changed while the parser unescaped it.
     *
     * The scan matches the UNESCAPED value, which is only sound while the
     * parser's unescaping agrees with the database's. PostgreSQL's unicode
     * identifier form is where they part: the lexer runs stripcslashes over
     * U&"pg_\0072ead_file" and hands the scan pg_2ead_file, while PostgreSQL
     * reads \0072 as "r" and resolves pg_read_file. The scan then vouches for
     * a name the database never sees.
     *
     * There is no way to tell from here which unescaping is the right one, so
     * a quoted token whose raw text is not simply its value between its own
     * quotes is refused rather than guessed at. A plainly quoted identifier
     * ("pg_read_file", `lo_get`) is unaffected and still reaches the scan.
     *
     * @throws UnsafeQueryException
     */
    private function assertNameSurvivedUnescaping(Token $token): void
    {
        $raw = $token->token;

        $closing = match ($raw === '' ? '' : $raw[0]) {
            '"' => '"',
            '`' => '`',
            '[' => ']',
            default => null,
        };

        if ($closing === null) {
            return;
        }

        if ($raw !== $raw[0].((string) $token->value).$closing) {
            throw UnsafeQueryException::notReadOnlySelect();
        }
    }

    /**
     * Whether a token can name a function or clause the allowlist forbids.
     *
     * Keywords and bare identifiers are the obvious case. Quoted identifiers are
     * the reason this is not a single comparison: the lexer types a
     * backtick-quoted identifier as a SYMBOL and a double-quoted one as a
     * STRING, and Token::extract() has already stripped the quotes from the
     * value, so a scan keyed only on keywords and bare identifiers never
     * inspects them. They are not inert text. A double-quoted string is the
     * standard identifier form in PostgreSQL, and SQLite resolves both forms, so
     * they reach the same functions the bare spelling does.
     *
     * Single-quoted strings stay out. Those are data literals, and excluding
     * them is what keeps an ordinary `WHERE name = 'copy'` working.
     */
    private function isIdentifierToken(Token $token): bool
    {
        if ($token->type === TokenType::Keyword || $token->type === TokenType::None) {
            return true;
        }

        if ($token->type === TokenType::Symbol) {
            return true;
        }

        return $token->type === TokenType::String
            && ($token->flags & Token::FLAG_STRING_DOUBLE_QUOTES) !== 0;
    }
}
