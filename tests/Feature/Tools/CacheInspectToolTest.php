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

// --- value_type for payloads that are not serialized ---

it('reports a plain unserialized string as a string instead of failing', function (): void {
    DB::table('cache')->insert([
        'key' => 'inspect_cache_plain_scalar',
        'value' => 'not-a-serialized-payload',
        'expiration' => now()->addHour()->getTimestamp(),
    ]);

    CacheInspectStubServer::tool(CacheInspectTool::class, [
        'store' => 'database',
        'key' => 'plain_scalar',
    ])
        ->assertOk()
        ->assertSee('"value_type": "string"');
});

it('reports a serialized false as a boolean', function (): void {
    DB::table('cache')->insert([
        'key' => 'inspect_cache_stored_false',
        'value' => serialize(false),
        'expiration' => now()->addHour()->getTimestamp(),
    ]);

    CacheInspectStubServer::tool(CacheInspectTool::class, [
        'store' => 'database',
        'key' => 'stored_false',
    ])
        ->assertOk()
        ->assertSee('"value_type": "boolean"');
});

it('reports a payload with trailing data as a string instead of the partial parse', function (): void {
    DB::table('cache')->insert([
        'key' => 'inspect_cache_trailing_data',
        'value' => 'i:5;junk',
        'expiration' => now()->addHour()->getTimestamp(),
    ]);

    CacheInspectStubServer::tool(CacheInspectTool::class, [
        'store' => 'database',
        'key' => 'trailing_data',
    ])
        ->assertOk()
        ->assertSee('"value_type": "string"');
});

it('never autoloads an enum class named in a cached payload', function (): void {
    $autoloaded = [];
    $recorder = function (string $class) use (&$autoloaded): void {
        $autoloaded[] = $class;
    };
    spl_autoload_register($recorder);

    DB::table('cache')->insert([
        'key' => 'inspect_cache_enum_payload',
        'value' => 'E:21:"InspectProbeEnum:Case";',
        'expiration' => now()->addHour()->getTimestamp(),
    ]);

    try {
        CacheInspectStubServer::tool(CacheInspectTool::class, [
            'store' => 'database',
            'key' => 'enum_payload',
        ])
            ->assertOk()
            ->assertSee('"value_type": "string"');
    } finally {
        spl_autoload_unregister($recorder);
    }

    expect($autoloaded)->not->toContain('InspectProbeEnum');
});

it('restores the application error handler after inspecting a value', function (): void {
    CacheInspectStubServer::tool(CacheInspectTool::class, [
        'store' => 'database',
        'key' => 'plain_scalar_missing',
    ])->assertOk();

    DB::table('cache')->insert([
        'key' => 'inspect_cache_plain_again',
        'value' => 'still-not-serialized',
        'expiration' => now()->addHour()->getTimestamp(),
    ]);

    CacheInspectStubServer::tool(CacheInspectTool::class, [
        'store' => 'database',
        'key' => 'plain_again',
    ])->assertOk();

    // A real E_WARNING, the level the tool mutes: it must reach Laravel's handler again.
    expect(fn () => unserialize('probe-after-inspect'))->toThrow(ErrorException::class);
});

it('forwards deprecations raised while parsing to the application error handler', function (): void {
    $seenLevels = [];
    $previous = set_error_handler(
        function (int $level, string $message, string $file, int $line) use (&$seenLevels, &$previous): bool {
            $seenLevels[] = $level;

            return $previous !== null && $previous($level, $message, $file, $line) !== false;
        },
    );

    DB::table('cache')->insert([
        'key' => 'inspect_cache_deprecated_format',
        'value' => 'S:3:"abc";',
        'expiration' => now()->addHour()->getTimestamp(),
    ]);

    try {
        CacheInspectStubServer::tool(CacheInspectTool::class, [
            'store' => 'database',
            'key' => 'deprecated_format',
        ])->assertOk();
    } finally {
        restore_error_handler();
    }

    expect($seenLevels)->toContain(E_DEPRECATED);
});
