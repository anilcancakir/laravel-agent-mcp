<?php

use Anilcancakir\LaravelAgentMcp\Tools\ConfigInspectTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only ConfigInspectTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts ConfigInspectTool for this test file only.
 */
final class ConfigInspectStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ConfigInspectTool::class,
    ];
}

// A recognizable secret embedded in a DSN-shaped config leaf. It MUST NEVER
// appear in the tool output: the *url* path is block-listed by default.
const CONFIG_DSN_SECRET = 'super-secret-dsn-password-9999';

// A recognizable APP_KEY value. The "key" block-list token matches the "app.key"
// path, so this MUST stay redacted even when the caller safe-lists it explicitly.
const CONFIG_APP_KEY_SECRET = 'APP_KEY_MARKER_AbCdEf0123456789';

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.config_inspect', true);
    config()->set('agent-mcp.audit.enabled', false);

    // Default block-list/safe-list from config; reset safe_list to empty so each
    // test opts in explicitly.
    config()->set('agent-mcp.config_inspect.block_list', [
        'password',
        'secret',
        'key',
        'token',
        'auth',
        'dsn',
        'url',
    ]);
    config()->set('agent-mcp.config_inspect.safe_list', []);

    // A known non-secret leaf to prove opt-in reveal works. No slash so the
    // assertion is not affected by json_encode escaping "/" to "\/".
    config()->set('app.timezone', 'TZ_ISTANBUL_MARKER');

    // A DSN-shaped leaf carrying a credential: must stay redacted even when the
    // caller explicitly safe-lists it (block-list wins).
    config()->set('database.connections.mysql.url', 'mysql://root:'.CONFIG_DSN_SECRET.'@127.0.0.1/app');
});

// --- tool-enabled gate (default OFF) ---

it('denies the call when config_inspect is disabled in config', function (): void {
    config()->set('agent-mcp.tools.config_inspect', false);

    ConfigInspectStubServer::tool(ConfigInspectTool::class, ['key' => 'app'])
        ->assertHasErrors();
});

// --- default: key-tree + types, NO values ---

it('returns the key-tree with gettype per leaf and no values by default', function (): void {
    $response = ConfigInspectStubServer::tool(ConfigInspectTool::class, ['key' => 'app'])
        ->assertOk();

    // The dot-path of a known leaf appears.
    $response->assertSee('app.timezone');

    // The leaf type appears, the value does not.
    $response->assertSee('string');
    $response->assertDontSee('TZ_ISTANBUL_MARKER');
});

// --- block-list wins over safe-list for a DSN/url path ---

it('keeps a url/dsn path redacted even with reveal_values and an explicit safe_keys entry', function (): void {
    $response = ConfigInspectStubServer::tool(ConfigInspectTool::class, [
        'key' => 'database.connections.mysql',
        'reveal_values' => true,
        'safe_keys' => [
            'database.connections.mysql.url',
        ],
    ])->assertOk();

    $response->assertDontSee(CONFIG_DSN_SECRET);
    $response->assertSee('[REDACTED]');
});

// --- APP_KEY (the highest-value secret) stays redacted even when safe-listed ---

it('keeps app.key redacted even with reveal_values and an explicit safe_keys entry', function (): void {
    config()->set('app.key', 'base64:'.CONFIG_APP_KEY_SECRET);

    $response = ConfigInspectStubServer::tool(ConfigInspectTool::class, [
        'key' => 'app',
        'reveal_values' => true,
        'safe_keys' => [
            'app.key',
        ],
    ])->assertOk();

    // The block-list token "key" matches the "app.key" path and wins over the
    // explicit safe_keys opt-in: the application key is never revealed.
    $response->assertDontSee(CONFIG_APP_KEY_SECRET);
    $response->assertSee('[REDACTED]');
});

// --- safe non-secret path reveals only when opted in AND safe-listed ---

it('reveals a non-secret safe path value only when reveal_values is true and it is safe-listed', function (): void {
    // Without the safe_keys opt-in: redacted.
    $hidden = ConfigInspectStubServer::tool(ConfigInspectTool::class, [
        'key' => 'app',
        'reveal_values' => true,
    ])->assertOk();

    $hidden->assertDontSee('TZ_ISTANBUL_MARKER');

    // With reveal_values + safe_keys: revealed.
    $shown = ConfigInspectStubServer::tool(ConfigInspectTool::class, [
        'key' => 'app',
        'reveal_values' => true,
        'safe_keys' => [
            'app.timezone',
        ],
    ])->assertOk();

    $shown->assertSee('TZ_ISTANBUL_MARKER');
});

// --- safe-listed but reveal_values omitted stays redacted ---

it('does not reveal a safe-listed value when reveal_values is omitted', function (): void {
    config()->set('agent-mcp.config_inspect.safe_list', [
        'app.timezone',
    ]);

    $response = ConfigInspectStubServer::tool(ConfigInspectTool::class, ['key' => 'app'])
        ->assertOk();

    $response->assertDontSee('TZ_ISTANBUL_MARKER');
});
