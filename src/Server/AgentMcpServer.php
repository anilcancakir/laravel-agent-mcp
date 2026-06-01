<?php

namespace Anilcancakir\LaravelAgentMcp\Server;

use Anilcancakir\LaravelAgentMcp\Http\StripsErrorTraces;
use Anilcancakir\LaravelAgentMcp\Tools\AppAboutTool;
use Anilcancakir\LaravelAgentMcp\Tools\CacheInspectTool;
use Anilcancakir\LaravelAgentMcp\Tools\CacheKeysTool;
use Anilcancakir\LaravelAgentMcp\Tools\CacheStatusTool;
use Anilcancakir\LaravelAgentMcp\Tools\ConfigInspectTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbActiveLocksTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbIndexHealthTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbMissingFkIndexesTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbQueryTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbRawSelectTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbSchemaTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbSlowQueriesTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbTableSizesTool;
use Anilcancakir\LaravelAgentMcp\Tools\EnvKeysTool;
use Anilcancakir\LaravelAgentMcp\Tools\EventListTool;
use Anilcancakir\LaravelAgentMcp\Tools\HorizonStatusTool;
use Anilcancakir\LaravelAgentMcp\Tools\InspectRouteTool;
use Anilcancakir\LaravelAgentMcp\Tools\ListRoutesTool;
use Anilcancakir\LaravelAgentMcp\Tools\MigrationsStatusTool;
use Anilcancakir\LaravelAgentMcp\Tools\QueueBacklogTool;
use Anilcancakir\LaravelAgentMcp\Tools\QueueFailedJobsTool;
use Anilcancakir\LaravelAgentMcp\Tools\ReadLogsTool;
use Anilcancakir\LaravelAgentMcp\Tools\RunArtisanTool;
use Anilcancakir\LaravelAgentMcp\Tools\ScheduleListTool;
use Anilcancakir\LaravelAgentMcp\Tools\StorageInfoTool;
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
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    Read-only access to this Laravel application for an authenticated agent.

    Before writing any query, call db_schema to learn the tables and columns.
    Use db_query for structured reads and db_raw_select for ad-hoc SELECTs.
    On errors or 500s, call read_logs immediately. run_artisan is available only
    when explicitly enabled and allowlisted by the operator.

    Investigation domains (operator opt-in per tool):
    - Queue health: queue_backlog, queue_failed_jobs, horizon_status
    - Database health: db_index_health, db_missing_fk_indexes, db_table_sizes,
      migrations_status, db_slow_queries (OFF), db_active_locks (OFF)
    - Cache: cache_status, cache_inspect, cache_keys (OFF)
    - App introspection: list_routes, inspect_route, app_about, schedule_list,
      event_list, env_keys, storage_info, config_inspect (OFF)

    All access is read-only at the database grant level; never assume a write will
    succeed. Results may be redacted as a best-effort secret filter. Sensitive tools
    are OFF by default and must be explicitly enabled by the operator.
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
        QueueBacklogTool::class,
        QueueFailedJobsTool::class,
        HorizonStatusTool::class,
        DbIndexHealthTool::class,
        DbMissingFkIndexesTool::class,
        DbTableSizesTool::class,
        DbSlowQueriesTool::class,
        DbActiveLocksTool::class,
        MigrationsStatusTool::class,
        CacheStatusTool::class,
        CacheInspectTool::class,
        CacheKeysTool::class,
        ListRoutesTool::class,
        InspectRouteTool::class,
        AppAboutTool::class,
        ScheduleListTool::class,
        EventListTool::class,
        ConfigInspectTool::class,
        EnvKeysTool::class,
        StorageInfoTool::class,
    ];
}
