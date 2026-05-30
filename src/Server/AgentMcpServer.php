<?php

namespace Anilcancakir\LaravelAgentMcp\Server;

use Anilcancakir\LaravelAgentMcp\Http\StripsErrorTraces;
use Anilcancakir\LaravelAgentMcp\Tools\DbQueryTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbRawSelectTool;
use Anilcancakir\LaravelAgentMcp\Tools\DbSchemaTool;
use Anilcancakir\LaravelAgentMcp\Tools\ReadLogsTool;
use Anilcancakir\LaravelAgentMcp\Tools\RunArtisanTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * The package's MCP server: exposes the five agent tools over both transports.
 *
 * Each tool gates itself on its config flag (shouldRegister) and enforces its
 * Sanctum ability authoritatively in handle(); this server only declares the set.
 * StripsErrorTraces overrides handle() so a thrown tool error never leaks a stack
 * trace, even with app.debug=true (Oracle IMP6).
 */
#[Name('agent-mcp')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    Read-only access to this Laravel application for an authenticated agent.

    Before writing any query, call db_schema to learn the tables and columns.
    Use db_query for structured reads and db_raw_select for ad-hoc SELECTs.
    On errors or 500s, call read_logs immediately. run_artisan is available only
    when explicitly enabled and allowlisted by the operator.

    All access is read-only at the database grant level; never assume a write will
    succeed. Results may be redacted as a best-effort secret filter.
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
    ];
}
