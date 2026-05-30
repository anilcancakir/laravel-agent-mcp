<?php

namespace Anilcancakir\LaravelAgentMcp\Tests\Stubs;

use Anilcancakir\LaravelAgentMcp\Tools\RunArtisanTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

/**
 * MCP server fixture that registers RunArtisanTool so the laravel/mcp testing
 * API (Server::tool(...)) can drive it by name through the real CallTool
 * pipeline. tools/call resolves the tool from the server's $tools by
 * its registered name (run_artisan), so the tool under test must be registered
 * here rather than only passed to tool().
 */
class StubArtisanServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        RunArtisanTool::class,
    ];
}
