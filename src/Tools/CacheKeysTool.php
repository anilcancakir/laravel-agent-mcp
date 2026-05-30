<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool: cache_keys
 *
 * Enumerates the keys in a cache store. This is the most sensitive cache surface
 * and ships DISABLED by default (Step 1): when the session lives in the same
 * store, the key NAMES are themselves live session identifiers, so the tool both
 * scopes to the configured cache prefix and EXCLUDES any key that carries the
 * session prefix.
 *
 * Per driver:
 *   - database: SELECT key, expiration FROM the cache table. The global cache
 *     prefix is stripped from each returned name; session-prefixed keys are
 *     dropped before output so live session IDs never leave the process.
 *   - redis: a SCAN cursor loop (match prefix*, count 1000) plus per-key TTL.
 *     NEVER the blocking KEYS command. Session-prefixed keys are excluded.
 *   - file: a count of entries plus expired-count only. File-store keys are sha1
 *     hashes (irreversible), so listing names is pointless and the count is the
 *     useful signal.
 *   - memcached / dynamodb: {error:'driver_opaque'} -- these drivers do not expose
 *     a safe enumeration primitive this tool will use.
 *
 * The tool never writes and never issues a destructive or cache-clearing command,
 * and never uses the blocking key-enumeration command (SCAN only).
 */
#[Name('cache_keys')]
class CacheKeysTool extends AbstractAgentTool
{
    /**
     * Redis SCAN page size: a bounded count per round so a large keyspace is
     * walked in chunks rather than blocking the server with KEYS.
     */
    private const SCAN_COUNT = 1000;

    /**
     * The optional Predis client FQCN, referenced as a string only so the file
     * loads on installations without a redis client.
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

        // 3. Resolve the target store.
        $storeName = $request->get('store');
        $storeName = ($storeName === null || $storeName === '')
            ? (string) config('cache.default')
            : (string) $storeName;

        $storeConfig = config("cache.stores.{$storeName}");

        if (! is_array($storeConfig)) {
            return Response::error("Unknown cache store: {$storeName}");
        }

        // 4. Branch on the store driver.
        $payload = match ($storeConfig['driver'] ?? null) {
            'database' => $this->databaseKeys($storeConfig),
            'redis' => $this->redisKeys($storeConfig),
            'file' => $this->fileKeys($storeConfig),
            'memcached', 'dynamodb' => [
                'store' => $storeConfig['driver'],
                'error' => 'driver_opaque',
            ],
            default => [
                'store' => $storeName,
                'error' => 'driver_opaque',
            ],
        };

        return $this->respond($payload);
    }

    /**
     * Database store: read key + expiration from the cache table, strip the global
     * cache prefix, and drop session-prefixed keys before output.
     *
     * @param  array<string, mixed>  $storeConfig
     * @return array<string, mixed>
     */
    private function databaseKeys(array $storeConfig): array
    {
        $connection = $storeConfig['connection'] ?? config('database.default');
        $table = (string) ($storeConfig['table'] ?? 'cache');
        $prefix = (string) config('cache.prefix');
        $now = now()->getTimestamp();

        $rows = DB::connection($connection)->table($table)->select(['key', 'expiration'])->get();

        $keys = [];
        $excluded = 0;

        foreach ($rows as $row) {
            $logicalKey = Str::startsWith($row->key, $prefix)
                ? Str::substr($row->key, Str::length($prefix))
                : $row->key;

            // Drop session keys: their names are live session IDs (Oracle).
            if ($this->isSessionKey($logicalKey)) {
                $excluded++;

                continue;
            }

            $expiration = (int) $row->expiration;

            $keys[] = [
                'key' => $logicalKey,
                'expires_in_seconds' => $expiration > $now ? $expiration - $now : 0,
                'expired' => $expiration <= $now,
            ];
        }

        return [
            'store' => 'database',
            'count' => count($keys),
            'session_keys_excluded' => $excluded,
            'keys' => $keys,
        ];
    }

    /**
     * Redis store: a SCAN cursor loop (NEVER KEYS) matching the cache prefix, with
     * per-key TTL, stripping the prefix and excluding session-prefixed keys. Gated
     * on redis being configured and a client being installed; degrades gracefully
     * otherwise.
     *
     * @param  array<string, mixed>  $storeConfig
     * @return array<string, mixed>
     */
    private function redisKeys(array $storeConfig): array
    {
        if (! $this->redisAvailable()) {
            return [
                'store' => 'redis',
                'available' => false,
                'reason' => 'redis is not configured or no redis client (phpredis/Predis) is installed',
            ];
        }

        $connectionName = $storeConfig['connection'] ?? 'cache';
        $redis = app('redis')->connection($connectionName);
        $prefix = (string) config('cache.prefix');

        // The package supports the phpredis client's concrete SCAN wrapper. The
        // Predis client wraps SCAN behind a magic __call whose return shape PHPStan
        // resolves against the raw \Redis mixin, not Laravel's tuple-returning
        // wrapper; rather than reach for a suppression, the Predis path degrades to
        // a documented {available:false} (the operator can use a phpredis client to
        // enumerate keys). This keeps the dominant production client fully supported.
        if (! $redis instanceof PhpRedisConnection) {
            return [
                'store' => 'redis',
                'available' => false,
                'reason' => 'key enumeration is supported on the phpredis client only; the configured client does not expose a SCAN with a portable return shape',
            ];
        }

        $keys = [];
        $excluded = 0;
        $cursor = null;

        // SCAN walks the keyspace in bounded pages; it never blocks the server the
        // way KEYS does. The loop ends when the cursor returns to 0.
        do {
            $result = $redis->scan($cursor, ['match' => $prefix.'*', 'count' => self::SCAN_COUNT]);

            // Laravel's phpredis wrapper returns false when the walk is complete and
            // empty, otherwise a [cursor, keys] tuple.
            if ($result === false) {
                break;
            }

            [$cursor, $batch] = $result;

            foreach ((array) $batch as $fullKey) {
                $logicalKey = Str::startsWith($fullKey, $prefix)
                    ? Str::substr($fullKey, Str::length($prefix))
                    : $fullKey;

                if ($this->isSessionKey($logicalKey)) {
                    $excluded++;

                    continue;
                }

                $ttl = (int) $redis->ttl($fullKey);

                $keys[] = [
                    'key' => $logicalKey,
                    'ttl_seconds' => $ttl < 0 ? null : $ttl,
                ];
            }
        } while ((int) $cursor !== 0);

        return [
            'store' => 'redis',
            'count' => count($keys),
            'session_keys_excluded' => $excluded,
            'keys' => $keys,
        ];
    }

    /**
     * File store: only a count of entries plus how many are expired. File-store
     * keys are sha1 hashes (irreversible), so the names carry no operator value and
     * are deliberately not listed.
     *
     * @param  array<string, mixed>  $storeConfig
     * @return array<string, mixed>
     */
    private function fileKeys(array $storeConfig): array
    {
        $path = $storeConfig['path'] ?? storage_path('framework/cache/data');

        if (! is_string($path) || ! is_dir($path)) {
            return [
                'store' => 'file',
                'count' => 0,
                'expired_count' => 0,
                'note' => 'Keys are sha1 hashes (irreversible); only counts are reported.',
            ];
        }

        $count = 0;
        $expired = 0;
        $now = now()->getTimestamp();

        // The file cache store writes the expiration timestamp as the first 10
        // bytes of each file. Reading only that header avoids deserializing the
        // (potentially sensitive) cached value.
        foreach ($this->cacheFiles($path) as $file) {
            $count++;

            $handle = @fopen($file, 'rb');

            if ($handle === false) {
                continue;
            }

            $expiresAt = (int) fread($handle, 10);
            fclose($handle);

            if ($expiresAt !== 0 && $expiresAt <= $now) {
                $expired++;
            }
        }

        return [
            'store' => 'file',
            'count' => $count,
            'expired_count' => $expired,
            'note' => 'Keys are sha1 hashes (irreversible); only counts are reported.',
        ];
    }

    /**
     * Enumerate the cache files under a file-store path (two-level sharded dirs).
     *
     * @return iterable<int, string>
     */
    private function cacheFiles(string $path): iterable
    {
        foreach (glob(rtrim($path, '/').'/*/*/*') ?: [] as $file) {
            if (is_file($file)) {
                yield $file;
            }
        }
    }

    /**
     * Whether a (prefix-stripped) logical key is a session key. Cache-backed
     * sessions key on the session id, and the names embed the session cookie name;
     * such keys are live session identifiers and must never leave the process.
     */
    private function isSessionKey(string $logicalKey): bool
    {
        $sessionCookie = config('session.cookie');

        if (! is_string($sessionCookie) || $sessionCookie === '') {
            return false;
        }

        return str_contains($logicalKey, $sessionCookie);
    }

    /**
     * Whether redis is configured AND a redis client is installed (phpredis or
     * Predis). The client classes are referenced by string so the file loads
     * without them.
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
