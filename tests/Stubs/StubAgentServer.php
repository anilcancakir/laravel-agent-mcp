<?php

namespace Anilcancakir\LaravelAgentMcp\Tests\Stubs;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

/**
 * Self-contained MCP server for the AbstractAgentTool tests.
 *
 * It exists only so the laravel/mcp testing API (Server::tool(...)) can drive the
 * stub tools through the real CallTool pipeline. It deliberately does NOT reference
 * AgentMcpServer so the base-tool behavior is testable in isolation.
 */
class StubAgentServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        StubAgentTool::class,
        StubNoopRegisterTool::class,
    ];
}
