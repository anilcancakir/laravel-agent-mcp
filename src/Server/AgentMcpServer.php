<?php

namespace Anilcancakir\LaravelAgentMcp\Server;

use Anilcancakir\LaravelAgentMcp\Http\StripsErrorTraces;
use Anilcancakir\LaravelAgentMcp\Tools\AppInfoTool;
use Anilcancakir\LaravelAgentMcp\Tools\CacheKeysTool;
use Anilcancakir\LaravelAgentMcp\Tools\CacheTool;
use Anilcancakir\LaravelAgentMcp\Tools\ConfigInspectTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbDiagnosticsTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbHealthTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbQueryTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbRawSelectTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbSchemaTool;
use Anilcancakir\LaravelAgentMcp\Tools\MigrationsStatusTool;
use Anilcancakir\LaravelAgentMcp\Tools\QueueStatusTool;
use Anilcancakir\LaravelAgentMcp\Tools\ReadLogsTool;
use Anilcancakir\LaravelAgentMcp\Tools\RoutesTool;
use Anilcancakir\LaravelAgentMcp\Tools\RunArtisanTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * The package's MCP server: exposes all agent tools over both transports.
 *
 * Each tool gates itself on its config enable flag (shouldRegister) and re-checks it
 * authoritatively in handle(); this server only declares the set. The server-admin key
 * is verified by KeyAuthMiddleware at the HTTP layer before any tool runs.
 * StripsErrorTraces overrides handle() so a thrown tool error never leaks a stack
 * trace, even with app.debug=true.
 */
#[Name('agent-mcp')]
#[Version('2.0.0')]
#[Instructions(<<<'MARKDOWN'
    Read-only inspection of this Laravel application: database, queue, cache, routes, app info and logs.
    Search for these tools when a task needs live data from the running app instead of a guess from the code.

    Database: call `db_schema` before any query, because it lists the real tables, columns, indexes and
    foreign keys. Then use `db_query` for a structured read of one table and `db_raw_select` for an
    ad-hoc SELECT with joins or aggregates. `migrations_status` shows which migrations ran, and
    `db_health` covers table sizes, index health and missing foreign keys.

    Logs: call `read_logs` as soon as a request fails or a 500 appears, because the stack trace is
    there.

    Queue: `queue_status` covers the backlog, failed jobs and Horizon.

    Cache: `cache` reports the store status and inspects one entry when given a key.

    Routes: `routes` lists the routes, or returns one route by name or uri.

    App info: `app_info` covers the about summary, env keys, storage, the schedule and events.

    A tool with sections uses its first section when none is given, and an unknown section is refused
    with the list of valid ones. Capped answers carry truncated when rows or lines were cut.

    All access is read-only at the connection level, so a write never succeeds. Output is redacted as a
    best-effort secret filter, so a redacted value is no proof the data is absent. These tools are off
    until the operator enables them: `run_artisan`, `db_diagnostics` (slow queries, locks), `cache_keys`
    and `config_inspect`.
    MARKDOWN)]
class AgentMcpServer extends Server
{
    use StripsErrorTraces;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        DbSchemaTool::class,
        DbQueryTool::class,
        DbRawSelectTool::class,
        ReadLogsTool::class,
        RunArtisanTool::class,
        MigrationsStatusTool::class,
        DbHealthTool::class,
        DbDiagnosticsTool::class,
        QueueStatusTool::class,
        CacheTool::class,
        CacheKeysTool::class,
        RoutesTool::class,
        AppInfoTool::class,
        ConfigInspectTool::class,
    ];
}
