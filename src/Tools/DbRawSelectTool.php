<?php

declare(strict_types=1);

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Anilcancakir\LaravelAgentMcp\Auditing\AuditLogger;
use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Anilcancakir\LaravelAgentMcp\Sql\SelectStatementValidator;
use Anilcancakir\LaravelAgentMcp\Sql\UnsafeQueryException;
use Anilcancakir\LaravelAgentMcp\Support\OutputRedactor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

/**
 * MCP tool db_raw_select: run an ad-hoc, read-only SELECT supplied as raw SQL.
 *
 * This is the highest-risk tool in the package (it accepts raw SQL), so it composes
 * every defense layer rather than trusting any single one:
 *   1. authorize()  - Sanctum read ability + tool-enabled gate (AbstractAgentTool).
 *   2. SelectStatementValidator::validate() - allowlist grammar; rejects anything
 *      that is not a single read-only SELECT BEFORE a query ever runs (Oracle CRIT2).
 *   3. auto-LIMIT - an unbounded SELECT is capped at config('agent-mcp.query.max_rows')
 *      to bound result size (DoS mitigation, paired with the connection timeout).
 *   4. $this->readonly() - the hardened read-only connection (PRAGMA query_only / a
 *      SELECT-only grant + statement timeout) is the REAL boundary (Oracle CRIT1): even
 *      if validation were bypassed, a write is refused at the connection layer.
 *   5. redactor() - best-effort redaction of secret-shaped values in the result rows.
 *
 * The order is load-bearing: VALIDATE strictly precedes EXECUTE. On any validation
 * failure the tool returns a generic JSON-RPC error and never echoes the offending SQL,
 * the matched token, or the driver error, so a rejection cannot leak the schema, the
 * attempted query, or driver internals back to the agent.
 */
class DbRawSelectTool extends AbstractAgentTool
{
    /**
     * The MCP tool name. Set explicitly so it is the plan's snake_case identifier
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
     * Reading the database requires the 'read' ability.
     */
    protected function requiredAbility(): string
    {
        return 'read';
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
                    .'A LIMIT is appended automatically when absent.'
                ),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative authorization gate, before any work (Oracle IMP5).
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

        // 4. Cap an unbounded statement so the result set cannot grow without limit.
        $sql = $this->ensureLimit($sql);

        // 5. Execute on the hardened read-only connection. Validation has already
        //    passed; the connection's read-only enforcement remains the final guard.
        $rows = $this->readonly()->select($sql);

        // 6. Best-effort redaction of secret-shaped values in the returned rows.
        $redacted = $this->redactor()->redactArray(array_map(
            static fn (object $row): array => (array) $row,
            $rows,
        ));

        return Response::json($redacted);
    }

    /**
     * Append a LIMIT clause when the outermost statement does not already declare one,
     * so every result set is bounded by config('agent-mcp.query.max_rows').
     *
     * The SQL has already passed the validator (a single read-only SELECT or read-only
     * CTE), so appending a trailing LIMIT cannot introduce a second statement or alter
     * the statement's read-only shape. A plain SELECT with an explicit LIMIT is left
     * untouched; anything else (no LIMIT, or a CTE whose outer body is unbounded) gets
     * the configured cap appended.
     */
    protected function ensureLimit(string $sql): string
    {
        if ($this->hasOuterLimit($sql)) {
            return $sql;
        }

        $maxRows = (int) config('agent-mcp.query.max_rows', 100);

        return rtrim(rtrim($sql), ';')." LIMIT {$maxRows}";
    }

    /**
     * Whether the outermost SELECT already declares a LIMIT.
     *
     * Re-parses with the same allowlist parser the validator uses (kept consistent with
     * the parsed-tree approach, never a raw-string regex). Only a top-level
     * SelectStatement exposes its LIMIT; a CTE (WithStatement) does not surface an outer
     * limit, so it is treated as unbounded and gets the cap appended, which always
     * bounds the result.
     */
    protected function hasOuterLimit(string $sql): bool
    {
        $parser = new Parser($sql);

        $statement = $parser->statements[0] ?? null;

        return $statement instanceof SelectStatement && $statement->limit !== null;
    }
}
