<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Closure;
use DateTimeZone;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionFunction;
use ReflectionProperty;
use Throwable;

/**
 * MCP tool: app_info
 *
 * One tool for the application's static surface, so the agent pays for one schema:
 * `about` (versions, drivers, cache flags, opcache, extensions), `env_keys`,
 * `storage`, `schedule` and `events`. A single enable flag, `agent-mcp.tools.app_info`,
 * opens every section; there is no per-section gate.
 *
 * `env_keys` reads array_keys($_ENV) only, so a value never enters the payload.
 * `storage` strips the credential keys from each disk before it is reported, but it
 * reports absolute root and link paths as configured. Closure listeners and closure
 * schedules are reported by file and line, never by body.
 */
#[Name('app_info')]
#[Description(<<<'TEXT'
    Application info: framework and PHP versions, environment and debug flag, drivers, cache state, opcache, loaded extensions, environment variable names, filesystem disks and storage links, scheduled tasks and event listeners; use it to learn how the app is configured and wired.

    - section picks one view and defaults to about: about, env_keys, storage, schedule or events.
    - about returns five parts, narrowed with parts: environment, cache, drivers, opcache, extensions.
    - env_keys lists names only, never values; storage strips disk credentials but shows root paths; schedule gives cron, next run and flags per task, with closure file and line.
    - events lists listeners including wildcards and flags ShouldQueue and ShouldBroadcast; narrow it with filter, a case-sensitive substring of the event name.
    TEXT)]
#[IsReadOnly]
class AppInfoTool extends AbstractAgentTool
{
    /**
     * Credential keys stripped from every disk config before it is reported. A
     * cloud disk embeds its access credentials under these keys; they are never
     * structural information an agent needs, so they are dropped at the source.
     *
     * @var array<int, string>
     */
    private const CREDENTIAL_KEYS = [
        'key',
        'secret',
        'password',
        'token',
    ];

    /**
     * Valid section names, the default first.
     *
     * @var array<int, string>
     */
    private const SECTIONS = [
        'about',
        'env_keys',
        'storage',
        'schedule',
        'events',
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'section' => $schema->string()
                ->enum(self::SECTIONS)
                ->description('The view to return. Defaults to about.'),
            'parts' => $schema->array()
                ->nullable()
                ->description(
                    'about only: subset of parts to return (environment, cache, drivers, opcache, extensions). '
                    .'Omit for all five.',
                ),
            'filter' => $schema->string()
                ->nullable()
                ->description('events only: case-sensitive substring of the event name. Omit for all events.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Validate the section, then audit the invocation shape (keys + types, never values).
        $section = $this->requestedSection($request, self::SECTIONS);

        $this->audit($this->argumentShape($request->all()), $section);

        // 3. Run the section and emit it through the shared redacting JSON responder.
        return $this->jsonResponse(match ($section) {
            'about' => $this->aboutSection($request),
            'env_keys' => $this->envKeysSection(),
            'storage' => $this->storageSection(),
            'schedule' => $this->scheduleSection(),
            default => $this->eventsSection($request),
        });
    }

    /**
     * The five about parts, narrowed to the requested subset when `parts` is given.
     *
     * @return array<string, mixed>
     */
    private function aboutSection(Request $request): array
    {
        $rawParts = $request->get('parts');
        $parts = is_array($rawParts) ? array_map('strval', $rawParts) : null;

        $all = [
            'environment' => $this->buildEnvironmentPart(),
            'cache' => $this->buildCachePart(),
            'drivers' => $this->buildDriversPart(),
            'opcache' => $this->buildOpcachePart(),
            'extensions' => $this->buildExtensionsPart(),
        ];

        return $parts !== null
            ? array_intersect_key($all, array_flip($parts))
            : $all;
    }

    /**
     * Core application environment information.
     *
     * @return array<string, mixed>
     */
    private function buildEnvironmentPart(): array
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
     * Cache optimization state flags.
     *
     * @return array<string, mixed>
     */
    private function buildCachePart(): array
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
     * Active driver names for the major Laravel services.
     *
     * @return array<string, string|null>
     */
    private function buildDriversPart(): array
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
     * OPcache status, guarded by function_exists.
     *
     * In CLI mode opcache_get_status() may return false or incomplete data depending
     * on the opcache.enable_cli ini setting; the runtime's answer is reported as is.
     *
     * @return array<string, mixed>
     */
    private function buildOpcachePart(): array
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
    private function buildExtensionsPart(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions);

        return $extensions;
    }

    /**
     * The NAMES of the process environment variables, never their values. array_keys()
     * drops the values at the source, so there is no value to leak downstream.
     *
     * @return array<string, mixed>
     */
    private function envKeysSection(): array
    {
        $keys = array_keys($_ENV);
        sort($keys);

        return [
            'note' => 'Reflects the live process environment ($_ENV). Names only; values are never read. '
                .'On a deployment with cached config (php artisan config:cache) the framework skips loading '
                .'the .env file, so $_ENV may contain only OS-level variables and not the .env keys.',
            'count' => count($keys),
            'keys' => $keys,
        ];
    }

    /**
     * The credential-stripped disk map and the resolved public storage links.
     *
     * @return array<string, mixed>
     */
    private function storageSection(): array
    {
        return [
            'default' => config('filesystems.default'),
            'disks' => $this->disks(),
            'links' => $this->links(),
        ];
    }

    /**
     * The configured disks with their credential keys stripped.
     *
     * @return array<string, array<string, mixed>>
     */
    private function disks(): array
    {
        $disks = config('filesystems.disks', []);

        if (! is_array($disks)) {
            return [];
        }

        $result = [];

        foreach ($disks as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            $result[$name] = $this->stripCredentials($config);
        }

        return $result;
    }

    /**
     * Remove the credential keys from a single disk config, preserving every
     * other (structural) attribute.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function stripCredentials(array $config): array
    {
        foreach (self::CREDENTIAL_KEYS as $credentialKey) {
            unset($config[$credentialKey]);
        }

        return $config;
    }

    /**
     * The configured public storage links, each annotated with its current
     * filesystem state (is_link / readlink / target exists). Reads only; never
     * creates or removes a link.
     *
     * @return array<int, array<string, mixed>>
     */
    private function links(): array
    {
        $links = config('filesystems.links', []);

        if (! is_array($links)) {
            return [];
        }

        $result = [];

        foreach ($links as $link => $target) {
            $linkPath = (string) $link;
            $isLink = is_link($linkPath);

            $result[] = [
                'link' => $linkPath,
                'target' => (string) $target,
                'is_link' => $isLink,
                'points_to' => $isLink ? (readlink($linkPath) ?: null) : null,
                'target_exists' => file_exists((string) $target),
            ];
        }

        return $result;
    }

    /**
     * Every registered schedule event, mapped to a safe serialisable shape.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scheduleSection(): array
    {
        $events = app(Schedule::class)->events();

        return array_map(
            fn (object $event): array => $this->describeEvent($event),
            $events,
        );
    }

    /**
     * Convert a schedule event to a safe, serialisable array.
     *
     * @return array<string, mixed>
     */
    private function describeEvent(object $event): array
    {
        $command = $this->resolveCommand($event);
        $nextRun = $this->resolveNextRun($event);

        return [
            'expression' => property_exists($event, 'expression') ? (string) $event->expression : '',
            'command' => $command,
            'description' => property_exists($event, 'description') ? $event->description : null,
            'timezone' => property_exists($event, 'timezone')
                ? ($event->timezone instanceof DateTimeZone
                    ? $event->timezone->getName()
                    : (string) $event->timezone)
                : null,
            'without_overlapping' => property_exists($event, 'withoutOverlapping')
                ? (bool) $event->withoutOverlapping
                : false,
            'on_one_server' => property_exists($event, 'onOneServer')
                ? (bool) $event->onOneServer
                : false,
            'even_in_maintenance' => property_exists($event, 'evenInMaintenanceMode')
                ? (bool) $event->evenInMaintenanceMode
                : false,
            'environments' => property_exists($event, 'environments')
                ? (array) $event->environments
                : [],
            'repeat_seconds' => property_exists($event, 'repeatSeconds')
                ? $event->repeatSeconds
                : null,
            'next_run' => $nextRun,
            'has_mutex' => property_exists($event, 'mutex') && $event->mutex !== null,
        ];
    }

    /**
     * Resolve a human-readable command summary for the event.
     *
     * For CallbackEvent (closure/callable), returns the framework summary
     * ("Callback") plus file:line when the underlying callback is a Closure.
     */
    private function resolveCommand(object $event): string
    {
        if ($event instanceof CallbackEvent) {
            $summary = $event->getSummaryForDisplay();

            // Attempt to add file:line for Closure callbacks.
            try {
                $callbackRef = new ReflectionProperty($event, 'callback');
                $callback = $callbackRef->getValue($event);

                if ($callback instanceof Closure) {
                    $rf = new ReflectionFunction($callback);
                    $file = $rf->getFileName();
                    $line = $rf->getStartLine();

                    if ($file !== false && $line !== false) {
                        return $summary.' ('.$file.':'.$line.')';
                    }
                }
            } catch (Throwable) {
                // Reflection may fail on internal closures; fall through to plain summary.
            }

            return $summary;
        }

        return $event->getSummaryForDisplay();
    }

    /**
     * Compute the next run date string via the framework's CronExpression wrapper.
     *
     * Returns the ISO-8601 string or null when computation fails.
     */
    private function resolveNextRun(object $event): ?string
    {
        try {
            $nextRun = $event->nextRunDate();

            return $nextRun?->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Every registered listener, wildcards included, optionally narrowed by a
     * case-sensitive substring of the event name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function eventsSection(Request $request): array
    {
        $filter = $request->get('filter');
        $filter = (is_string($filter) && $filter !== '') ? $filter : null;

        $dispatcher = app('events');

        // getRawListeners() is typed as array<> by PHPDoc; assign directly.
        $merged = array_merge($dispatcher->getRawListeners(), $this->readWildcards($dispatcher));

        $payload = [];

        foreach ($merged as $event => $rawListeners) {
            $eventKey = (string) $event;

            if ($filter !== null && ! str_contains($eventKey, $filter)) {
                continue;
            }

            $payload[$eventKey] = [
                'listeners' => array_values(
                    array_map(
                        fn (mixed $listener): array => $this->classifyListener($listener),
                        is_array($rawListeners) ? $rawListeners : [],
                    ),
                ),
            ];
        }

        return $payload;
    }

    /**
     * Read the protected Dispatcher::$wildcards property via reflection.
     *
     * getRawListeners() only exposes $this->listeners (exact-match events).
     * Wildcard patterns are stored separately in $wildcards and must be
     * read via reflection to surface them.
     *
     * @return array<string, array<int, mixed>>
     */
    private function readWildcards(object $dispatcher): array
    {
        try {
            $property = new ReflectionProperty(Dispatcher::class, 'wildcards');
            $value = $property->getValue($dispatcher);

            return is_array($value) ? $value : [];
        } catch (Throwable) {
            // If the Dispatcher implementation changes and $wildcards disappears,
            // degrade gracefully rather than throwing.
            return [];
        }
    }

    /**
     * Classify a single listener entry into a structured shape.
     *
     * @return array<string, mixed>
     */
    private function classifyListener(mixed $listener): array
    {
        // Array-style callable: [object|class-string, method].
        if (is_array($listener) && count($listener) === 2) {
            $target = $listener[0];
            $method = (string) ($listener[1] ?? '');
            $class = is_object($target) ? get_class($target) : (string) $target;

            return [
                'type' => 'array',
                'class' => $class,
                'method' => $method,
                'should_queue' => $this->implementsShouldQueue($class),
                'should_broadcast' => $this->implementsShouldBroadcast($class),
            ];
        }

        // String-style: "ClassName@method" or just "ClassName".
        if (is_string($listener)) {
            $class = (string) explode('@', $listener)[0];

            return [
                'type' => 'string',
                'listener' => $listener,
                'should_queue' => $this->implementsShouldQueue($class),
                'should_broadcast' => $this->implementsShouldBroadcast($class),
            ];
        }

        // Closure: report file and line only; the body is not exposed.
        if ($listener instanceof Closure) {
            return $this->classifyClosure($listener);
        }

        // Unknown shape (wrapped dispatcher internals, etc.).
        return ['type' => 'unknown'];
    }

    /**
     * Extract file:line from a Closure via reflection.
     *
     * @return array<string, mixed>
     */
    private function classifyClosure(Closure $closure): array
    {
        try {
            $rf = new ReflectionFunction($closure);
            $file = $rf->getFileName();
            $line = $rf->getStartLine();

            return [
                'type' => 'closure',
                'file' => ($file !== false) ? $file : null,
                'line' => ($line !== false) ? $line : null,
            ];
        } catch (Throwable) {
            return ['type' => 'closure'];
        }
    }

    /**
     * Check whether a class name implements ShouldQueue.
     *
     * Uses class_implements() which does NOT autoload; safe for class names that
     * may not exist in the current process (returns false instead of throwing).
     */
    private function implementsShouldQueue(string $class): bool
    {
        if (! class_exists($class, false)) {
            return false;
        }

        $interfaces = class_implements($class);

        return is_array($interfaces)
            && isset($interfaces[ShouldQueue::class]);
    }

    /**
     * Check whether a class name implements ShouldBroadcast.
     *
     * Same no-autoload guard as implementsShouldQueue().
     */
    private function implementsShouldBroadcast(string $class): bool
    {
        if (! class_exists($class, false)) {
            return false;
        }

        $interfaces = class_implements($class);

        return is_array($interfaces)
            && isset($interfaces[ShouldBroadcast::class]);
    }
}
