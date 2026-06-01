<?php

use Anilcancakir\LaravelAgentMcp\Tools\CacheKeysTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only CacheKeysTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts CacheKeysTool for this test file only.
 */
final class CacheKeysStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        CacheKeysTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    // cache_keys defaults OFF; the behavioral tests enable it here.
    config()->set('agent-mcp.tools.cache_keys', true);
    config()->set('agent-mcp.audit.enabled', false);

    config()->set('cache.prefix', 'keys_cache_');
    config()->set('session.cookie', 'agentmcp_session');

    config()->set('cache.stores.database', [
        'driver' => 'database',
        'connection' => 'testbench',
        'table' => 'cache',
        'lock_connection' => 'testbench',
    ]);

    Schema::dropIfExists('cache');
    Schema::create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    // Two ordinary cache keys (prefixed).
    DB::table('cache')->insert([
        'key' => 'keys_cache_dashboard_stats',
        'value' => serialize('payload'),
        'expiration' => now()->addHour()->getTimestamp(),
    ]);
    DB::table('cache')->insert([
        'key' => 'keys_cache_product_index',
        'value' => serialize('payload'),
        'expiration' => now()->addHour()->getTimestamp(),
    ]);

    // A session key written into the cache store: its name embeds the session
    // cookie prefix and the value is a live session ID. It MUST be excluded.
    DB::table('cache')->insert([
        'key' => 'keys_cache_agentmcp_session_LIVESESSIONID12345',
        'value' => serialize('session-payload'),
        'expiration' => now()->addHour()->getTimestamp(),
    ]);
});

// --- tool-enabled gate ---

it('denies the call when cache_keys is disabled in config', function (): void {
    config()->set('agent-mcp.tools.cache_keys', false);

    CacheKeysStubServer::tool(CacheKeysTool::class, ['store' => 'database'])
        ->assertHasErrors();
});

// --- database keys with prefix stripped ---

it('lists database keys with the cache prefix stripped', function (): void {
    $response = CacheKeysStubServer::tool(CacheKeysTool::class, ['store' => 'database'])
        ->assertOk();

    // Prefix-stripped names must appear.
    $response->assertSee('dashboard_stats');
    $response->assertSee('product_index');

    // The raw prefix must not leak in the key names.
    $response->assertDontSee('keys_cache_dashboard_stats');
});

// --- session prefix excluded ---

it('excludes keys carrying the session cookie prefix so live session IDs do not leak', function (): void {
    $response = CacheKeysStubServer::tool(CacheKeysTool::class, ['store' => 'database'])
        ->assertOk();

    // The live session ID must never appear in the output.
    $response->assertDontSee('LIVESESSIONID12345');
    $response->assertDontSee('agentmcp_session');
});
