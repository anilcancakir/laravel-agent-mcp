<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool: cache_status
 *
 * Read-only snapshot of the cache subsystem and the framework optimization
 * state. It reports:
 *
 *   - The configured cache stores (driver + per-driver introspectability), the
 *     default store, and the global cache key prefix.
 *   - The optimization state: whether config / routes / events are cached, plus
 *     the on-disk cache paths so an operator can see where they live.
 *   - opcache, when the SAPI exposes it: this process's opcache status. The CLI
 *     SAPI (where an MCP stdio call runs) usually has opcache disabled, so the
 *     reading reflects THIS process, not the FPM worker that serves web traffic;
 *     the payload labels that caveat.
 *   - A session_overlap_risk flag: when the session driver is redis sharing the
 *     same Redis connection as the cache store, cache key listings can surface
 *     live session IDs. The flag warns the operator before they enable cache_keys.
 *
 * The tool reads configuration and framework helpers only; it never reads cached
 * values and never mutates any store (no clearing, eviction, or opcache reset).
 */
#[Name('cache_status')]
class CacheStatusTool extends AbstractAgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit the invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. Assemble the read-only snapshot.
        return $this->respond([
            'default' => config('cache.default'),
            'prefix' => config('cache.prefix'),
            'stores' => $this->stores(),
            'optimization' => $this->optimization(),
            'opcache' => $this->opcache(),
            'session_overlap_risk' => $this->sessionOverlapRisk(),
        ]);
    }

    /**
     * The configured cache stores with their driver and whether this package can
     * introspect their keys (drives what cache_keys / cache_inspect can do).
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
     * so the operator is warned before enabling cache_keys (Oracle: session-in-redis
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
     * Redact (defense-in-depth) and emit the payload as a JSON text response.
     *
     * @param  array<string, mixed>  $payload
     */
    private function respond(array $payload): Response
    {
        $redacted = $this->redactor()->redactArray($payload);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
