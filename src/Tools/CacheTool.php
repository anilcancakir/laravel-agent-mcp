<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool: cache
 *
 * One tool for the cache subsystem, split by whether a `key` is given.
 *
 * Without a key it returns a read-only snapshot of the configured stores, the
 * framework optimization state, this process's opcache status (the CLI SAPI that
 * runs an MCP stdio call usually differs from the FPM workers serving web
 * traffic, so the payload labels that caveat) and a session_overlap_risk flag
 * that warns when redis sessions share the cache connection, where cache key
 * listings can surface live session IDs.
 *
 * With a key it inspects that single key WITHOUT deserializing or returning its
 * value by default. TTL is read straight from the storage layer (the database
 * cache table's expiration column, or Redis TTL) because Repository::get()
 * deserializes the value, which can instantiate arbitrary objects.
 *
 * The raw value is the high-risk surface (a cached value may be a plaintext
 * secret or a serialized object). It is returned ONLY when ALL of these hold,
 * gating FIRST and treating OutputRedactor as the last net:
 *
 *   1. raw_value=true is explicitly requested.
 *   2. config('agent-mcp.cache.allow_value_read') is true (operator opt-in).
 *   3. The key name does NOT match the secret-token block-list.
 *
 * Any failed gate yields [REDACTED] in place of the value, never the value. The
 * tool never writes (no put/forget/flush, no opcache reset).
 *
 * CONNECTION BOUNDARY: the database cache store is read on the connection it is
 * configured to use (cache.stores.*.connection), which may differ from the
 * package's hardened read-only clone. Only read-only query-builder methods are
 * used; the dedicated readonly DB grant is the enforcement boundary. See the README.
 */
#[Name('cache')]
#[Description(<<<'TEXT'
    Cache health and single-key inspection. Without `key`: configured stores and drivers, whether config, routes and events are cached, an opcache summary, and a session-overlap risk flag (sessions and the cache share one redis connection, the case cache_keys guards against). With `key` (without the global prefix): whether it exists, its TTL and value type, from the default store unless `store` names another.

    - The raw value comes back only when `raw_value` is true AND the operator set cache.allow_value_read AND the key is not block-listed; otherwise it is [REDACTED].
    - Start without `key` for any cache investigation.
    TEXT)]
#[IsReadOnly]
class CacheTool extends AbstractAgentTool
{
    /**
     * The redaction marker used in place of a withheld raw value.
     */
    private const REDACTED = '[REDACTED]';

    /**
     * The optional Redis client FQCN, referenced as a string only so the file
     * loads on installations without the redis extension or Predis.
     */
    private const PREDIS_CLIENT = 'Predis\Client';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()
                ->description('The cache key to inspect (without the global cache prefix). Omit for the cache status snapshot.'),
            'store' => $schema->string()
                ->nullable()
                ->description('Cache store name for the key. Omit to use the default store.'),
            'raw_value' => $schema->boolean()
                ->nullable()
                ->description('Request the raw cached value. Returned only when cache.allow_value_read is true and the key is not block-listed; otherwise [REDACTED].'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit the invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. No key selects the status snapshot, a key selects the inspection.
        $key = $request->get('key');

        if ($key === null) {
            return $this->jsonResponse($this->status());
        }

        return $this->inspect($request, (string) $key);
    }

    /**
     * The status snapshot: stores, optimization state, opcache and session overlap.
     *
     * @return array<string, mixed>
     */
    private function status(): array
    {
        return [
            'default' => config('cache.default'),
            'prefix' => config('cache.prefix'),
            'stores' => $this->stores(),
            'optimization' => $this->optimization(),
            'opcache' => $this->opcache(),
            'session_overlap_risk' => $this->sessionOverlapRisk(),
        ];
    }

    /**
     * The configured cache stores with their driver and whether this package can
     * introspect their keys (drives what cache_keys and the key inspection can do).
     *
     * @return array<string, mixed>
     */
    private function stores(): array
    {
        $stores = config('cache.stores', []);

        if (! is_array($stores)) {
            return [];
        }

        $result = [];

        foreach ($stores as $name => $config) {
            $driver = is_array($config) ? ($config['driver'] ?? null) : null;

            $result[$name] = [
                'driver' => $driver,
                'introspectable' => $this->introspectable(is_string($driver) ? $driver : ''),
            ];
        }

        return $result;
    }

    /**
     * Whether a driver's keys can be enumerated by this package (informational; the
     * actual enumeration lives in cache_keys and is gated separately).
     */
    private function introspectable(string $driver): bool
    {
        return in_array($driver, ['database', 'redis', 'file'], true);
    }

    /**
     * Framework optimization state plus the on-disk cache paths, mirroring the
     * data the artisan about/optimize commands report.
     *
     * @return array<string, mixed>
     */
    private function optimization(): array
    {
        return [
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'events_cached' => app()->eventsAreCached(),
            'config_cache_path' => app()->getCachedConfigPath(),
            'routes_cache_path' => app()->getCachedRoutesPath(),
            'events_cache_path' => app()->getCachedEventsPath(),
        ];
    }

    /**
     * opcache status for THIS process, guarded by the SAPI exposing the extension.
     * Passing false to opcache_get_status omits the per-script cache list (large
     * and irrelevant here). The note records the CLI-vs-FPM caveat.
     *
     * @return array<string, mixed>
     */
    private function opcache(): array
    {
        if (! function_exists('opcache_get_status')) {
            return [
                'available' => false,
                'reason' => 'opcache extension not loaded in this SAPI',
            ];
        }

        $status = opcache_get_status(false);

        if ($status === false) {
            return [
                'available' => false,
                'reason' => 'opcache is disabled in this SAPI',
                'note' => 'This reflects the current SAPI (often CLI); the FPM workers serving web traffic may differ.',
            ];
        }

        return [
            'available' => true,
            'enabled' => $status['opcache_enabled'] ?? null,
            'note' => 'This reflects the current SAPI (often CLI); the FPM workers serving web traffic may differ.',
            'memory_usage' => $status['memory_usage'] ?? null,
            'statistics' => $status['opcache_statistics'] ?? null,
        ];
    }

    /**
     * Flag the case where the session lives in redis on the SAME connection as the
     * default cache store. There, cache key listings can surface live session IDs,
     * so the operator is warned before enabling cache_keys (session-in-redis
     * key names are live session identifiers).
     */
    private function sessionOverlapRisk(): bool
    {
        if (config('session.driver') !== 'redis') {
            return false;
        }

        $defaultStore = config('cache.default');
        $cacheConfig = config("cache.stores.{$defaultStore}");

        if (! is_array($cacheConfig) || ($cacheConfig['driver'] ?? null) !== 'redis') {
            return false;
        }

        // Both session and cache resolve to a redis connection; an overlap on the
        // SAME connection means cache keys and session keys share a keyspace.
        $sessionConnection = config('session.connection') ?? 'default';
        $cacheConnection = $cacheConfig['connection'] ?? 'default';

        return $sessionConnection === $cacheConnection;
    }

    /**
     * Resolve the target store and branch on its driver to inspect one key.
     */
    private function inspect(Request $request, string $key): Response
    {
        // 1. Resolve the target store + key.
        if ($key === '') {
            return Response::error('A non-empty key is required.');
        }

        $storeName = $request->get('store');
        $storeName = ($storeName === null || $storeName === '')
            ? (string) config('cache.default')
            : (string) $storeName;

        $storeConfig = config("cache.stores.{$storeName}");

        if (! is_array($storeConfig)) {
            return Response::error("Unknown cache store: {$storeName}");
        }

        $wantsValue = (bool) $request->get('raw_value', false);

        // 2. Branch on the store driver.
        $payload = match ($storeConfig['driver'] ?? null) {
            'database' => $this->inspectDatabase($storeConfig, $key, $wantsValue),
            'redis' => $this->inspectRedis($storeConfig, $key, $wantsValue),
            default => [
                'store' => $storeName,
                'key' => $key,
                'available' => false,
                'reason' => 'inspection is not supported for this cache driver',
            ],
        };

        return $this->jsonResponse($payload);
    }

    /**
     * Inspect a key in a database cache store: read the row directly from the
     * cache table so TTL and value type are derived without going through the
     * deserializing repository.
     *
     * @param  array<string, mixed>  $storeConfig
     * @return array<string, mixed>
     */
    private function inspectDatabase(array $storeConfig, string $key, bool $wantsValue): array
    {
        $connection = $storeConfig['connection'] ?? config('database.default');
        $table = (string) ($storeConfig['table'] ?? 'cache');
        $prefixedKey = $this->prefixedKey($key);

        $row = DB::connection($connection)->table($table)->where('key', $prefixedKey)->first();

        if ($row === null) {
            return [
                'store' => $storeConfig['driver'],
                'key' => $key,
                'exists' => false,
            ];
        }

        $expiration = (int) $row->expiration;
        $ttl = $expiration - now()->getTimestamp();

        // Unserialize ONLY to derive the value type; the value itself is revealed
        // only after the gate passes (step below). The database cache store stores
        // the serialized payload in the value column.
        $unserialized = $this->safeUnserialize($row->value);

        return [
            'store' => $storeConfig['driver'],
            'key' => $key,
            'exists' => true,
            'ttl_seconds' => $ttl > 0 ? $ttl : 0,
            'value_type' => gettype($unserialized),
            'value' => $this->gatedValue($key, $unserialized, $wantsValue),
        ];
    }

    /**
     * Inspect a key in a redis cache store. Redis is optional: the path is gated by
     * the redis extension / Predis being present AND a redis config block, and
     * degrades to a structured payload otherwise (never a fatal on a no-redis box).
     *
     * @param  array<string, mixed>  $storeConfig
     * @return array<string, mixed>
     */
    private function inspectRedis(array $storeConfig, string $key, bool $wantsValue): array
    {
        if (! $this->redisAvailable()) {
            return [
                'store' => 'redis',
                'key' => $key,
                'available' => false,
                'reason' => 'redis is not configured or no redis client (phpredis/Predis) is installed',
            ];
        }

        $connectionName = $storeConfig['connection'] ?? 'cache';
        $redis = app('redis')->connection($connectionName);
        $prefixedKey = $this->prefixedKey($key);

        // TTL is read directly via the Redis TTL command (NOT Repository::get,
        // which deserializes). -2 = missing, -1 = no expiry.
        $ttl = (int) $redis->ttl($prefixedKey);

        if ($ttl === -2) {
            return [
                'store' => 'redis',
                'key' => $key,
                'exists' => false,
            ];
        }

        // The raw redis string is read only to derive a type and (when gated open)
        // reveal the value; it is never deserialized into a PHP object here.
        $raw = $redis->get($prefixedKey);

        return [
            'store' => 'redis',
            'key' => $key,
            'exists' => true,
            'ttl_seconds' => $ttl < 0 ? null : $ttl,
            'value_type' => gettype($raw),
            'value' => $this->gatedValue($key, $raw, $wantsValue),
        ];
    }

    /**
     * Apply the value gate: the raw value is revealed ONLY when it was requested
     * AND value reads are enabled AND the key is not block-listed. Any failed gate
     * returns [REDACTED]. Gating happens here, FIRST; OutputRedactor is the last
     * net, never the sole guard.
     */
    private function gatedValue(string $key, mixed $value, bool $wantsValue): mixed
    {
        if (! $wantsValue) {
            return self::REDACTED;
        }

        if (! (bool) config('agent-mcp.cache.allow_value_read', false)) {
            return self::REDACTED;
        }

        if ($this->keyIsBlockListed($key)) {
            return self::REDACTED;
        }

        return $value;
    }

    /**
     * Whether the key name matches a secret-token in the block-list. A live cache
     * key whose name contains token/secret/password/etc is treated as carrying a
     * sensitive value and never revealed, even with the read flag on.
     */
    private function keyIsBlockListed(string $key): bool
    {
        $blockList = config('agent-mcp.config_inspect.block_list', []);

        if (! is_array($blockList)) {
            return false;
        }

        $haystack = strtolower($key);

        foreach ($blockList as $token) {
            if (is_string($token) && $token !== '' && str_contains($haystack, strtolower($token))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefix a logical key with the global cache prefix to form the storage key.
     */
    private function prefixedKey(string $key): string
    {
        return (string) config('cache.prefix').$key;
    }

    /**
     * Best-effort unserialize used ONLY to derive a value type. Anything that is not
     * a clean serialized payload comes back as-is, so the type reflects what is
     * stored: a plain string, a payload with trailing data, or one that parses only
     * with a warning. Objects come back as __PHP_Incomplete_Class. Payloads that name
     * an enum are not parsed at all: unserialize() autoloads enum classes even with
     * allowed_classes off, and inspecting a key must never run application code.
     */
    private function safeUnserialize(mixed $value): mixed
    {
        if (! is_string($value) || $this->namesEnum($value)) {
            return $value;
        }

        // 1. A bad payload raises E_WARNING (E_NOTICE on older PHP), which Laravel's
        //    handler would throw; record it instead. Every other level, deprecations
        //    included, still reaches the application's handler.
        $failed = false;
        $previous = null;
        $previous = set_error_handler(
            static function (int $level, string $message, string $file, int $line) use (&$failed, &$previous): bool {
                if ($level === E_WARNING || $level === E_NOTICE) {
                    $failed = true;

                    return true;
                }

                return $previous !== null && $previous($level, $message, $file, $line) !== false;
            },
        );

        try {
            $result = unserialize($value, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        // 2. A warning means a failed or partial parse, and false without the literal
        //    'b:0;' means a failed one; either way report the value as stored.
        if ($failed || ($result === false && $value !== 'b:0;')) {
            return $value;
        }

        return $result;
    }

    /**
     * Whether a serialized payload contains an enum token (E:<length>:"Class:Case").
     * A string value that merely looks like one is reported as a string, which is the
     * safe side of the trade.
     */
    private function namesEnum(string $value): bool
    {
        return preg_match('/(?:^|[;{}])E:\d+:"/', $value) === 1;
    }

    /**
     * Whether redis is configured AND a redis client is installed (phpredis or
     * Predis). Mirrors the optional-package guard: the client classes are referenced
     * by string so the file loads without them.
     */
    private function redisAvailable(): bool
    {
        return config('database.redis') !== null
            && (extension_loaded('redis') || class_exists(self::PREDIS_CLIENT));
    }
}
