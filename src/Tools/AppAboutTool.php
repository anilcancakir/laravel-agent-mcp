<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool: app_about
 *
 * Replicates the data surface of Artisan's `about` command: application versions,
 * environment/debug/maintenance state, cache optimization flags, driver names,
 * opcache status (guarded by function_exists), and loaded extensions.
 *
 * An optional `sections` argument (array of strings) narrows which sections are
 * returned. Valid section names: environment, cache, drivers, opcache, extensions.
 * All sections are returned when the argument is omitted.
 *
 * No database or file mutations are made; this tool is strictly read-only.
 */
#[Name('app_about')]
#[Description(<<<'TEXT'
    Snapshot the application environment: framework and PHP versions, environment and debug flag, drivers, cache state, and loaded extensions. Mirrors the `php artisan about` command. Use it for a quick read of how the app is configured.

    Usage:
    - Optionally pass `sections` to limit the output (for example environment, cache, drivers); omit it for everything.
    - Read-only.
    TEXT)]
class AppAboutTool extends AbstractAgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sections' => $schema->array()
                ->nullable()
                ->description(
                    'Optional subset of sections to return. Valid values: environment, cache, drivers, opcache, extensions. '
                    .'Omit to return all sections.',
                ),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. Resolve the requested sections filter.
        $rawSections = $request->get('sections');
        $sections = is_array($rawSections) ? array_map('strval', $rawSections) : null;

        // 4. Build each section and filter to the requested subset.
        $all = [
            'environment' => $this->buildEnvironmentSection(),
            'cache' => $this->buildCacheSection(),
            'drivers' => $this->buildDriversSection(),
            'opcache' => $this->buildOpcacheSection(),
            'extensions' => $this->buildExtensionsSection(),
        ];

        $payload = $sections !== null
            ? array_intersect_key($all, array_flip($sections))
            : $all;

        // 5. Redact and return.
        $redacted = $this->redactor()->redactArray($payload);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * Core application environment information (mirrors AboutCommand "Environment" pane).
     *
     * @return array<string, mixed>
     */
    private function buildEnvironmentSection(): array
    {
        return [
            'laravel_version' => app()->version(),
            'php_version' => phpversion(),
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'maintenance_mode' => app()->isDownForMaintenance(),
            'timezone' => config('app.timezone'),
            'locale' => app()->getLocale(),
        ];
    }

    /**
     * Cache optimization state flags (mirrors AboutCommand "Cache" pane).
     *
     * @return array<string, mixed>
     */
    private function buildCacheSection(): array
    {
        $compiledViewsPath = config('view.compiled');
        $viewFiles = (is_string($compiledViewsPath) && $compiledViewsPath !== '')
            ? count((array) glob($compiledViewsPath.'/*.php'))
            : 0;

        return [
            'configuration_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'events_cached' => app()->eventsAreCached(),
            'views_compiled_count' => $viewFiles,
        ];
    }

    /**
     * Active driver names for the major Laravel services (mirrors AboutCommand "Drivers" pane).
     *
     * @return array<string, string|null>
     */
    private function buildDriversSection(): array
    {
        return [
            'cache' => (string) config('cache.default'),
            'queue' => (string) config('queue.default'),
            'session' => (string) config('session.driver'),
            'database' => (string) config('database.default'),
            'mail' => (string) config('mail.default'),
            'broadcasting' => config('broadcasting.default') !== null
                ? (string) config('broadcasting.default')
                : null,
            'logging' => (string) config('logging.default'),
        ];
    }

    /**
     * OPcache status (guarded: function_exists check before call).
     *
     * Note: in CLI mode opcache_get_status() may return false or incomplete data
     * depending on the opcache.enable_cli ini setting. The tool reports what the
     * runtime returns without interpretation.
     *
     * @return array<string, mixed>
     */
    private function buildOpcacheSection(): array
    {
        if (! function_exists('opcache_get_status')) {
            return ['available' => false, 'reason' => 'opcache extension not loaded'];
        }

        $status = opcache_get_status(false);

        if ($status === false) {
            return ['available' => false, 'reason' => 'opcache disabled or not running'];
        }

        return [
            'available' => true,
            'enabled' => $status['opcache_enabled'] ?? false,
            'cache_full' => $status['cache_full'] ?? false,
            'used_memory_bytes' => $status['memory_usage']['used_memory'] ?? null,
            'free_memory_bytes' => $status['memory_usage']['free_memory'] ?? null,
            'cached_scripts' => $status['opcache_statistics']['num_cached_scripts'] ?? null,
            'hits' => $status['opcache_statistics']['hits'] ?? null,
            'misses' => $status['opcache_statistics']['misses'] ?? null,
        ];
    }

    /**
     * Loaded PHP extension names, sorted for stable output.
     *
     * @return array<int, string>
     */
    private function buildExtensionsSection(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions);

        return $extensions;
    }
}
