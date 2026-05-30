<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Throwable;

/**
 * MCP tool: cache_inspect
 *
 * Inspects a single cache key WITHOUT deserializing or returning its value by
 * default. The default response is metadata only: {exists, ttl_seconds,
 * value_type}. TTL is read directly from the storage layer (the database cache
 * table's expiration column, or Redis TTL) rather than through the cache
 * repository, because Repository::get() deserializes the value (which can
 * instantiate arbitrary objects and is exactly the side effect we avoid here).
 *
 * The raw value is the high-risk surface (a cached value may be a plaintext
 * secret or a serialized object). It is returned ONLY when ALL of these hold,
 * gating FIRST and treating OutputRedactor as the last net (Oracle value-gating
 * order):
 *
 *   1. raw_value=true is explicitly requested.
 *   2. config('agent-mcp.cache.allow_value_read') is true (operator opt-in).
 *   3. The key name does NOT match the secret-token block-list.
 *
 * Any failed gate yields [REDACTED] in place of the value, never the value.
 *
 * The tool never writes (no put/forget/flush) and never deserializes a value it
 * is not allowed to reveal.
 */
#[Name('cache_inspect')]
class CacheInspectTool extends AbstractAgentTool
{
    /**
     * The redaction marker used in place of a withheld raw value.
     */
    private const REDACTED = '[REDACTED]';

    /**
     * The optional Redis client FQCNs, referenced as strings only so the file
     * loads on installations without the redis extension or Predis.
     */
    private const PREDIS_CLIENT = 'Predis\Client';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'store' => $schema->string()
                ->nullable()
                ->description('Cache store name. Omit to use the default store.'),
            'key' => $schema->string()
                ->description('The cache key to inspect (without the global cache prefix).'),
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

        // 3. Resolve the target store + key.
        $storeName = $request->get('store');
        $storeName = ($storeName === null || $storeName === '')
            ? (string) config('cache.default')
            : (string) $storeName;

        $key = (string) $request->get('key');

        if ($key === '') {
            return Response::error('A non-empty key is required.');
        }

        $storeConfig = config("cache.stores.{$storeName}");

        if (! is_array($storeConfig)) {
            return Response::error("Unknown cache store: {$storeName}");
        }

        $wantsValue = (bool) $request->get('raw_value', false);

        // 4. Branch on the store driver.
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

        return $this->respond($payload);
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
     * net, never the sole guard (Oracle value-gating order).
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
     * Best-effort unserialize used ONLY to derive a value type. A value that does
     * not unserialize (e.g. a plain scalar Redis string) is returned as-is so the
     * type reflects what is actually stored. Object instantiation is disabled so
     * inspecting a key never triggers a wakeup side effect.
     */
    private function safeUnserialize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        try {
            $result = unserialize($value, ['allowed_classes' => false]);
        } catch (Throwable) {
            return $value;
        }

        // unserialize returns false on failure; distinguish a genuine serialized
        // false ('b:0;') from a failed parse so the type stays accurate.
        if ($result === false && $value !== 'b:0;') {
            return $value;
        }

        return $result;
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

    /**
     * Redact (defense-in-depth, last net) and emit the payload as a JSON response.
     *
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload): Response
    {
        $redacted = $this->redactor()->redactArray($payload);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
