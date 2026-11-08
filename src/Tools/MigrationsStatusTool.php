<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Anilcancakir\LaravelAgentMcp\Auditing\AuditLogger;
use Anilcancakir\LaravelAgentMcp\Database\CatalogQuery;
use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Anilcancakir\LaravelAgentMcp\Support\OutputRedactor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool: migrations_status
 *
 * Reports which migrations have run, grouped into batches, by reading the
 * migrations table over the hardened readonly connection. It deliberately does
 * NOT read the filesystem migration files: detecting PENDING migrations requires
 * enumerating the on-disk migration directory, which is outside this read-only
 * catalog tool's contract. Instead the tool reports the ran migrations by batch and flags pending
 * detection as {pending_detection:'filesystem_required'} so the caller knows the
 * gap is intentional, not a missing feature.
 *
 * When the migrations table itself is absent the tool degrades to a structured
 * payload rather than erroring (a fresh database with no migrations table is a
 * valid state, not a fault).
 */
#[Name('migrations_status')]
#[Description(<<<'TEXT'
    Migration status: which migrations ran and in which batch, read from the migrations table. Use it to confirm a migration ran or to read the batch history.

    - Takes no arguments; each migration name appears once, under its batch.
    - Pending migrations are not detected, because that needs the filesystem; the output says so with pending_detection.
    TEXT)]
#[IsReadOnly]
class MigrationsStatusTool extends AbstractAgentTool
{
    /**
     * The Laravel migration repository table name.
     */
    private const MIGRATIONS_TABLE = 'migrations';

    /**
     * The shared read-only catalog-SQL boundary (engine detect + bound SELECT).
     */
    private readonly CatalogQuery $catalog;

    public function __construct(
        ReadonlyConnectionResolver $connectionResolver,
        OutputRedactor $outputRedactor,
        AuditLogger $auditLogger,
        CatalogQuery $catalog,
    ) {
        parent::__construct($connectionResolver, $outputRedactor, $auditLogger);

        $this->catalog = $catalog;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. A database without a migrations table is a valid state, not a fault.
        if (! in_array(self::MIGRATIONS_TABLE, $this->catalog->knownTables(), true)) {
            return $this->jsonResponse([
                'migrations_table_present' => false,
                'reason' => 'no migrations table on the readonly connection',
                'pending_detection' => 'filesystem_required',
            ]);
        }

        // 4. Read the ran migrations grouped by batch. The table name is a package
        //    constant, never agent input, so embedding it is not an injection
        //    surface (no bindings needed here).
        $rows = $this->catalog->select(
            'SELECT migration, batch FROM '.self::MIGRATIONS_TABLE.' ORDER BY batch ASC, migration ASC',
        );

        // Each migration is named once, under its batch; a separate flat list would repeat every name.
        $batches = [];

        foreach ($rows as $row) {
            $batches[(int) $row->batch][] = $row->migration;
        }

        return $this->jsonResponse([
            'migrations_table_present' => true,
            'ran_count' => count($rows),
            'latest_batch' => $batches === [] ? 0 : max(array_keys($batches)),
            'batches' => $batches,
            // Pending detection needs the on-disk migration directory; this tool is
            // a read-only catalog tool and does not touch the filesystem.
            'pending_detection' => 'filesystem_required',
        ]);
    }
}
