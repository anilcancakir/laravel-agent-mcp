<?php

declare(strict_types=1);

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool: db_schema
 *
 * Exposes read-only database schema introspection over the hardened readonly
 * connection. Two modes:
 *
 *   - No arguments: returns the list of tables (names + sizes) on the
 *     configured readonly connection.
 *   - table argument given: returns columns, indexes, and foreign keys for
 *     that specific table.
 *
 * The table argument is validated against getTables() before any introspection
 * takes place, so an unknown table name is rejected with a clean error instead
 * of leaking a raw driver exception.
 *
 * Output is run through the redactor because column defaults and comments can
 * carry secrets (Oracle IMP4: redaction is best-effort defense-in-depth).
 */
#[Name('db_schema')]
class DbSchemaTool extends AbstractAgentTool
{
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
            'table' => $schema->string()
                ->nullable()
                ->description('Optional table name. Omit to list all tables; provide to inspect columns, indexes, and foreign keys.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative ability + tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit the invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. Dispatch to the appropriate introspection path.
        $table = $request->get('table');

        if ($table === null || $table === '') {
            return $this->listTables();
        }

        return $this->describeTable((string) $table);
    }

    /**
     * Return the list of all tables on the readonly connection with their sizes.
     */
    private function listTables(): Response
    {
        $connectionName = (string) config('agent-mcp.connection', 'readonly');

        $tables = Schema::connection($connectionName)->getTables();

        $redacted = $this->redactor()->redactArray(
            array_map(fn (array $t): array => $t, $tables),
        );

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');
    }

    /**
     * Return columns, indexes, and foreign keys for a known table.
     * Rejects unknown tables with a clean error (no driver exception leak).
     */
    private function describeTable(string $table): Response
    {
        $connectionName = (string) config('agent-mcp.connection', 'readonly');

        // Validate the table exists before introspecting so a typo or injection
        // attempt yields a clean denial, not a raw driver exception (Oracle IMP6:
        // strip stack traces; here we go further and never attempt the call).
        $knownTables = array_column(Schema::connection($connectionName)->getTables(), 'name');

        if (! in_array($table, $knownTables, true)) {
            return Response::error("Unknown table: {$table}");
        }

        $columns = Schema::connection($connectionName)->getColumns($table);
        $indexes = Schema::connection($connectionName)->getIndexes($table);
        $foreignKeys = Schema::connection($connectionName)->getForeignKeys($table);

        $result = [
            'table' => $table,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
        ];

        $redacted = $this->redactor()->redactArray($result);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
