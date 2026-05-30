<?php

use Anilcancakir\LaravelAgentMcp\Tools\EnvKeysTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only EnvKeysTool, keeping these tests isolated
// from AgentMcpServer.

/**
 * Inline stub server that hosts EnvKeysTool for this test file only.
 */
final class EnvKeysStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        EnvKeysTool::class,
    ];
}

// A recognizable process-env value that MUST NEVER appear in the output: the
// tool emits key NAMES only.
const ENV_SECRET_VALUE = 'ENV_VALUE_NEVER_LEAKS_4242';

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.env_keys', true);
    config()->set('agent-mcp.audit.enabled', false);

    // Seed a recognizable name/value into the process env so we can assert the
    // name is listed and the value is absent.
    $_ENV['AGENT_MCP_TEST_ENV_KEY'] = ENV_SECRET_VALUE;
});

afterEach(function (): void {
    unset($_ENV['AGENT_MCP_TEST_ENV_KEY']);
});

// --- tool-enabled gate ---

it('denies the call when env_keys is disabled in config', function (): void {
    config()->set('agent-mcp.tools.env_keys', false);

    EnvKeysStubServer::tool(EnvKeysTool::class, [])
        ->assertHasErrors();
});

// --- key names only, never a value ---

it('returns env key names only and never a value', function (): void {
    $response = EnvKeysStubServer::tool(EnvKeysTool::class, [])
        ->assertOk();

    // The seeded key NAME appears.
    $response->assertSee('AGENT_MCP_TEST_ENV_KEY');

    // Its VALUE must never appear.
    $response->assertDontSee(ENV_SECRET_VALUE);
});
