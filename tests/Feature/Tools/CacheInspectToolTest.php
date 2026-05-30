<?php

use Anilcancakir\LaravelAgentMcp\Tools\CacheInspectTool;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only CacheInspectTool, keeping these tests
// isolated from AgentMcpServer.

/**
 * Inline stub server that hosts CacheInspectTool for this test file only.
 */
final class CacheInspectStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        CacheInspectTool::class,
    ];
}

// A value placed in the cache that MUST NOT leak when value reads are disabled.
const INSPECT_SECRET_VALUE = 'PLAINTEXT_CACHE_VALUE_xyz789';

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.cache_inspect', true);
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.cache.allow_value_read', false);

    config()->set('cache.prefix', 'inspect_cache_');

    // Build a database cache store fixture so TTL is read via a direct table SELECT.
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

    // Seed a plain non-secret key/value pair (serialized like the database cache store does).
    DB::table('cache')->insert([
        'key' => 'inspect_cache_report_data',
        'value' => serialize(INSPECT_SECRET_VALUE),
        'expiration' => now()->addHour()->getTimestamp(),
    ]);

    // Seed a block-listed key name (contains "token") with a value.
    DB::table('cache')->insert([
        'key' => 'inspect_cache_user_token',
        'value' => serialize('a-live-token-value'),
        'expiration' => now()->addHour()->getTimestamp(),
    ]);
});

// --- tool-enabled gate ---

it('denies the call when cache_inspect is disabled in config', function (): void {
    config()->set('agent-mcp.tools.cache_inspect', false);

    CacheInspectStubServer::tool(CacheInspectTool::class, ['store' => 'database', 'key' => 'report_data'])
        ->assertHasErrors();
});

// --- default metadata-only ---

it('returns exists, ttl_seconds and value_type metadata by default', function (): void {
    $response = CacheInspectStubServer::tool(CacheInspectTool::class, [
        'store' => 'database',
        'key' => 'report_data',
    ])->assertOk();

    $response->assertSee('exists');
    $response->assertSee('ttl_seconds');
    $response->assertSee('value_type');
});

// --- raw value withheld by default (no flag) ---

it('withholds the raw value when raw_value is not requested', function (): void {
    $response = CacheInspectStubServer::tool(CacheInspectTool::class, [
        'store' => 'database',
        'key' => 'report_data',
    ])->assertOk();

    $response->assertDontSee(INSPECT_SECRET_VALUE);
});

// --- raw value withheld when allow_value_read is false ---

it('redacts the raw value when allow_value_read is false even with raw_value=true', function (): void {
    config()->set('agent-mcp.cache.allow_value_read', false);

    $response = CacheInspectStubServer::tool(CacheInspectTool::class, [
        'store' => 'database',
        'key' => 'report_data',
        'raw_value' => true,
    ])->assertOk();

    $response->assertDontSee(INSPECT_SECRET_VALUE);
    $response->assertSee('[REDACTED]');
});

// --- block-listed key name redacted even when allowed ---

it('redacts a block-listed key name value even when allow_value_read is true', function (): void {
    config()->set('agent-mcp.cache.allow_value_read', true);

    $response = CacheInspectStubServer::tool(CacheInspectTool::class, [
        'store' => 'database',
        'key' => 'user_token',
        'raw_value' => true,
    ])->assertOk();

    $response->assertDontSee('a-live-token-value');
    $response->assertSee('[REDACTED]');
});

// --- raw value returned when flag on AND key safe ---

it('returns the raw value when allow_value_read is true and the key is not block-listed', function (): void {
    config()->set('agent-mcp.cache.allow_value_read', true);

    $response = CacheInspectStubServer::tool(CacheInspectTool::class, [
        'store' => 'database',
        'key' => 'report_data',
        'raw_value' => true,
    ])->assertOk();

    $response->assertSee(INSPECT_SECRET_VALUE);
});
