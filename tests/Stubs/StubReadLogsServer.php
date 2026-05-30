<?php

declare(strict_types=1);

namespace Anilcancakir\LaravelAgentMcp\Tests\Stubs;

use Anilcancakir\LaravelAgentMcp\Tools\ReadLogsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

/**
 * Dedicated MCP server for the ReadLogsTool tests: registers only read_logs so the
 * laravel/mcp test API can resolve it by name through the real CallTool pipeline,
 * without touching the shared StubAgentServer (which is owned by the base-tool step).
 */
class StubReadLogsServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ReadLogsTool::class,
    ];
}
