<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Anilcancakir\LaravelAgentMcp\Auditing\AuditLogger;
use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Anilcancakir\LaravelAgentMcp\Sql\SelectStatementValidator;
use Anilcancakir\LaravelAgentMcp\Sql\UnsafeQueryException;
use Anilcancakir\LaravelAgentMcp\Support\OutputRedactor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use PhpMyAdmin\SqlParser\Components\Limit;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\WithStatement;
use PhpMyAdmin\SqlParser\TokenType;

/**
 * MCP tool db_raw_select: run an ad-hoc, read-only SELECT supplied as raw SQL.
 *
 * This is the highest-risk tool in the package (it accepts raw SQL), so it composes
 * every defense layer rather than trusting any single one:
 *   1. authorize()  - tool-enabled gate (AbstractAgentTool); the server-admin key
 *      was already verified by the HTTP middleware before the tool ran.
 *   2. SelectStatementValidator::validate() - allowlist grammar; rejects anything
 *      that is not a single read-only SELECT BEFORE a query ever runs.
 *   3. row cap - an explicit LIMIT above config('agent-mcp.query.max_rows') is refused
 *      and an unbounded SELECT gets the cap appended, so result size stays bounded
 *      (DoS mitigation, paired with the connection timeout).
 *   4. $this->readonly() - the hardened read-only connection (PRAGMA query_only / a
 *      SELECT-only grant + statement timeout) is the REAL boundary: even
 *      if validation were bypassed, a write is refused at the connection layer.
 *   5. redactor() - best-effort redaction of secret-shaped values in the result rows.
 *
 * The order is load-bearing: VALIDATE strictly precedes EXECUTE. On any validation
 * failure the tool returns a generic JSON-RPC error and never echoes the offending SQL,
 * the matched token, or the driver error, so a rejection cannot leak the schema, the
 * attempted query, or driver internals back to the agent.
 */
#[Description(<<<'TEXT'
    Raw SQL SELECT for JOINs, subqueries, aggregates and window functions that db_query cannot express; use db_query for simple lookups.

    - One SELECT or read-only CTE; writes, stacked statements and file functions are refused before execution.
    - Rows are capped at agent-mcp.query.max_rows: omit LIMIT to get the cap with truncated true when cut, or give a LIMIT within it.
    - Call db_schema first for exact table and column names.
    TEXT)]
#[IsReadOnly]
class DbRawSelectTool extends AbstractAgentTool
{
    /**
     * The MCP tool name. Set explicitly so it is the snake_case identifier
     * (db_raw_select) rather than the kebab-cased class basename, and so the base's
     * config gates resolve against config('agent-mcp.tools.db_raw_select').
     */
    protected string $name = 'db_raw_select';

    protected string $description = 'Run a single ad-hoc, read-only SELECT supplied as raw SQL '
        .'against the read-only database connection.';

    public function __construct(
        ReadonlyConnectionResolver $connectionResolver,
        OutputRedactor $outputRedactor,
        AuditLogger $auditLogger,
        protected readonly SelectStatementValidator $validator,
    ) {
        parent::__construct($connectionResolver, $outputRedactor, $auditLogger);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sql' => $schema->string()
                ->required()
                ->description(
                    'A single read-only SELECT statement. Writes, multiple statements, '
                    .'file/side-effect functions, and non-SELECT statements are rejected. '
                    .'A LIMIT above agent-mcp.query.max_rows is refused; without one the cap is applied.'
                ),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative authorization gate, before any work.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit the invocation by argument SHAPE (never the raw SQL value).
        $this->audit($this->argumentShape($request->all()));

        $sql = (string) $request->get('sql', '');

        // 3. VALIDATE before EXECUTE. A rejection returns a generic error and never
        //    reaches the connection; the message deliberately omits the offending SQL,
        //    the matched token, and any driver detail.
        try {
            $this->validator->validate($sql);
        } catch (UnsafeQueryException) {
            return Response::error(
                'The query was rejected: only a single read-only SELECT statement is allowed.'
            );
        }

        // 4. Bound the result. An explicit LIMIT stands only when it is a plain number
        //    within the cap; without one the cap plus one row is appended, so a full
        //    extra row is what tells a cut result from one that fitted exactly.
        $maxRows = (int) config('agent-mcp.query.max_rows', 100);
        $parser = new Parser($sql);
        $limit = $this->outerLimit($parser);

        if ($limit !== null && ! $this->withinCap($limit, $maxRows)) {
            return Response::error(
                "The query LIMIT must be a plain number of at most {$maxRows} rows "
                .'(the agent-mcp.query.max_rows cap); lower it or omit it.'
            );
        }

        if ($limit === null) {
            $sql = $this->statementBody($parser)."\nLIMIT ".($maxRows + 1);
        }

        // 5. Execute on the hardened read-only connection. Validation has already
        //    passed; the connection's read-only enforcement remains the final guard.
        $rows = $this->readonly()->select($sql);

        // 6. Cut to the cap and best-effort redact secret-shaped values in what is left.
        $redacted = $this->redactor()->redactArray(array_map(
            static fn (object $row): array => (array) $row,
            array_slice($rows, 0, $maxRows),
        ));

        $payload = [
            'rows' => $redacted,
        ];

        if (count($rows) > $maxRows) {
            $payload['truncated'] = true;
        }

        return Response::json($payload);
    }

    /**
     * The LIMIT of the statement that produces the result, or null when it has none.
     *
     * For a CTE that is the statement after the WITH terms, not the WithStatement
     * itself, which carries no LIMIT of its own. The validator has already proved
     * that statement is a single SELECT.
     */
    private function outerLimit(Parser $parser): ?Limit
    {
        $statement = $parser->statements[0] ?? null;

        if ($statement instanceof WithStatement) {
            $statement = $statement->cteStatementParser?->statements[0] ?? null;
        }

        return $statement instanceof SelectStatement ? $statement->limit : null;
    }

    /**
     * Whether an explicit LIMIT bounds the result within the cap.
     *
     * The parser leaves a bind placeholder as the string '?', and SQLite reads a
     * negative row count as no limit at all, so only a non-negative integer counts.
     * The offset is irrelevant: it skips rows, it never adds any.
     */
    private function withinCap(Limit $limit, int $maxRows): bool
    {
        return is_int($limit->rowCount) && $limit->rowCount >= 0 && $limit->rowCount <= $maxRows;
    }

    /**
     * The statement text up to its terminating delimiter, ready for an appended clause.
     *
     * Rebuilt from the parsed tokens because a raw-string trim misses a ';' followed
     * by a comment, and the clause would then land after the statement it must bound.
     * A ';' inside a literal is part of a string token, so it never ends the cut.
     */
    private function statementBody(Parser $parser): string
    {
        $body = '';

        foreach ($parser->list->tokens ?? [] as $token) {
            if ($token->type === TokenType::Delimiter) {
                break;
            }

            $body .= $token->token;
        }

        return $body;
    }
}
