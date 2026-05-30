<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool `db_query`: structured, bound, read-only queries over the hardened
 * readonly connection.
 *
 * Security model:
 *   - Table name validated against Schema::getTables() before any query is built.
 *   - Column names (select, conditions, order_by) validated against
 *     Schema::getColumns($table) — never interpolated raw.
 *   - Operator values validated against a FIXED enum; anything outside the enum
 *     is rejected with a clean error BEFORE query construction (never raw-SQL).
 *   - User-supplied condition values are ALWAYS passed as PDO bindings via the
 *     query builder's where() / whereIn() — never concatenated into SQL.
 *   - Result limit clamped to config('agent-mcp.query.max_rows').
 *   - Output rows redacted through OutputRedactor (best-effort defense-in-depth).
 */
#[Name('db_query')]
class DbQueryTool extends AbstractAgentTool
{
    /**
     * Fixed operator allowlist. ONLY these strings may appear in a WHERE clause.
     * Any other value is rejected before the query builder is touched.
     *
     * @var array<int, string>
     */
    private const ALLOWED_OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'like', 'in'];

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
            'table' => $schema->string()->required()->description('Table to query.'),

            'query_type' => $schema->string()
                ->enum(['find', 'where', 'count'])
                ->required()
                ->description('find returns one row by id; where returns a filtered list; count returns an integer.'),

            'id' => $schema->integer()
                ->nullable()
                ->description('Primary key value for a find query.'),

            'conditions' => $schema->array()
                ->nullable()
                ->description('Array of condition objects. Each object must have column, operator and value.'),

            'select' => $schema->array()
                ->nullable()
                ->description('Columns to include in the result. Defaults to all columns.'),

            'limit' => $schema->integer()
                ->nullable()
                ->description('Maximum rows to return. Clamped to agent-mcp.query.max_rows.'),

            'order_by' => $schema->string()
                ->nullable()
                ->description('Column to order results by.'),

            'order_dir' => $schema->string()
                ->enum(['asc', 'desc'])
                ->nullable()
                ->description('Sort direction. Defaults to asc.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative authorization gate — must be first.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        $this->audit($this->argumentShape($request->all()));

        $table = (string) $request->get('table', '');
        $queryType = (string) $request->get('query_type', 'where');

        // 2. Validate table exists on the readonly connection.
        $validationError = $this->validateTable($table);

        if ($validationError !== null) {
            return $validationError;
        }

        // 3. Validate and normalize the conditions array.
        /** @var array<int, mixed>|null $rawConditions */
        $rawConditions = $request->get('conditions');
        $conditions = is_array($rawConditions) ? $rawConditions : [];

        $conditionError = $this->validateConditions($table, $conditions);

        if ($conditionError !== null) {
            return $conditionError;
        }

        // 4. Validate the select columns when provided.
        /** @var array<int, string>|null $selectColumns */
        $selectColumns = $request->get('select');

        if (is_array($selectColumns)) {
            $selectError = $this->validateColumns($table, $selectColumns, 'select');

            if ($selectError !== null) {
                return $selectError;
            }
        }

        // 5. Validate order_by column when provided.
        $orderBy = $request->get('order_by');
        $orderBy = is_string($orderBy) && $orderBy !== '' ? $orderBy : null;

        if ($orderBy !== null) {
            $orderError = $this->validateColumns($table, [$orderBy], 'order_by');

            if ($orderError !== null) {
                return $orderError;
            }
        }

        // 6. Clamp limit to max_rows.
        $maxRows = (int) config('agent-mcp.query.max_rows', 100);
        $requestedLimit = $request->get('limit');
        $limit = is_numeric($requestedLimit) ? min((int) $requestedLimit, $maxRows) : $maxRows;

        $orderDir = $request->get('order_dir');
        $orderDir = in_array($orderDir, ['asc', 'desc'], true) ? $orderDir : 'asc';

        // 7. Route to the appropriate query_type handler.
        return match ($queryType) {
            'find' => $this->runFind($table, $request, $selectColumns),
            'count' => $this->runCount($table, $conditions),
            default => $this->runWhere($table, $conditions, $selectColumns, $limit, $orderBy, $orderDir),
        };
    }

    // -------------------------------------------------------------------------
    // Query type handlers
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, string>|null  $selectColumns
     */
    private function runFind(string $table, Request $request, ?array $selectColumns): Response
    {
        $id = $request->get('id');

        // Build query using the readonly connection; the id value is a PDO binding.
        $query = $this->readonly()->table($table);

        if (is_array($selectColumns) && $selectColumns !== []) {
            $query = $query->select($selectColumns);
        }

        // PDO binding: ->find() passes the value through a parameterized where clause.
        $row = $query->find($id);

        $payload = $row === null ? null : (array) $row;
        $redacted = $payload === null ? null : $this->redactor()->redactArray([$payload])[0] ?? null;

        return Response::text(json_encode(['row' => $redacted], JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * @param  array<int, mixed>  $conditions
     */
    private function runCount(string $table, array $conditions): Response
    {
        $query = $this->readonly()->table($table);
        $query = $this->applyConditions($query, $conditions);

        $count = $query->count();

        return Response::text(json_encode(['count' => $count], JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * @param  array<int, mixed>  $conditions
     * @param  array<int, string>|null  $selectColumns
     */
    private function runWhere(
        string $table,
        array $conditions,
        ?array $selectColumns,
        int $limit,
        ?string $orderBy,
        string $orderDir,
    ): Response {
        $query = $this->readonly()->table($table);

        if (is_array($selectColumns) && $selectColumns !== []) {
            $query = $query->select($selectColumns);
        }

        $query = $this->applyConditions($query, $conditions);
        $query = $query->limit($limit);

        if ($orderBy !== null) {
            $query = $query->orderBy($orderBy, $orderDir);
        }

        $rows = $query->get()->map(fn (mixed $row): array => (array) $row)->all();
        $redacted = $this->redactor()->redactArray($rows);

        return Response::text(json_encode(['rows' => $redacted], JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    // -------------------------------------------------------------------------
    // Condition application (bindings only)
    // -------------------------------------------------------------------------

    /**
     * Apply validated conditions to the query builder. All values pass through
     * PDO bindings: the column name is already validated against the schema,
     * the operator is already confirmed against the fixed allowlist.
     *
     * @param  array<int, mixed>  $conditions
     */
    private function applyConditions(
        Builder $query,
        array $conditions,
    ): Builder {
        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $column = (string) ($condition['column'] ?? '');
            $operator = strtolower((string) ($condition['operator'] ?? ''));
            $value = $condition['value'] ?? null;

            if ($operator === 'in') {
                // whereIn binds each element of the array as a separate parameter.
                $query = $query->whereIn($column, is_array($value) ? $value : [$value]);
            } else {
                // ->where($col, $op, $value) passes $value as a PDO binding.
                $query = $query->where($column, $operator, $value);
            }
        }

        return $query;
    }

    // -------------------------------------------------------------------------
    // Validation helpers
    // -------------------------------------------------------------------------

    /**
     * Validate the table name exists on the readonly connection.
     * Returns a denial Response on failure, null on success.
     */
    private function validateTable(string $table): ?Response
    {
        if ($table === '') {
            return Response::error('The table parameter is required.');
        }

        $connectionName = (string) config('agent-mcp.connection', 'readonly');
        $tables = Schema::connection($connectionName)->getTables();
        $tableNames = array_column($tables, 'name');

        if (! in_array($table, $tableNames, true)) {
            return Response::error("Unknown table: {$table}.");
        }

        return null;
    }

    /**
     * Validate every condition's column and operator.
     *
     * @param  array<int, mixed>  $conditions
     */
    private function validateConditions(string $table, array $conditions): ?Response
    {
        foreach ($conditions as $index => $condition) {
            if (! is_array($condition)) {
                return Response::error("Condition at index {$index} must be an object.");
            }

            $column = isset($condition['column']) ? (string) $condition['column'] : '';
            $operator = isset($condition['operator']) ? (string) $condition['operator'] : '';

            // Operator must come from the fixed allowlist — no raw strings.
            if (! in_array(strtolower((string) $operator), self::ALLOWED_OPERATORS, true)) {
                return Response::error(
                    "Operator '{$operator}' is not allowed. Permitted operators: "
                    .implode(', ', self::ALLOWED_OPERATORS).'.',
                );
            }

            $columnError = $this->validateColumns($table, [(string) $column], "conditions[{$index}].column");

            if ($columnError !== null) {
                return $columnError;
            }
        }

        return null;
    }

    /**
     * Validate that all given column names exist in the table schema.
     * Returns a denial Response on any unknown column, null when all are valid.
     *
     * @param  array<int, string>  $columns
     */
    private function validateColumns(string $table, array $columns, string $context): ?Response
    {
        $connectionName = (string) config('agent-mcp.connection', 'readonly');
        $schemaColumns = Schema::connection($connectionName)->getColumns($table);
        $validNames = array_column($schemaColumns, 'name');

        foreach ($columns as $column) {
            if (! in_array($column, $validNames, true)) {
                return Response::error("Unknown column '{$column}' in {$context} for table '{$table}'.");
            }
        }

        return null;
    }
}
