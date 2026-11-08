<?php

namespace Anilcancakir\LaravelAgentMcp;

use Anilcancakir\LaravelAgentMcp\Commands\CallToolCommand;
use Anilcancakir\LaravelAgentMcp\Commands\InstallCommand;
use Anilcancakir\LaravelAgentMcp\Commands\ListToolsCommand;
use Anilcancakir\LaravelAgentMcp\Commands\StdioBridgeCommand;
use Anilcancakir\LaravelAgentMcp\Commands\ToolSchemaCommand;
use Anilcancakir\LaravelAgentMcp\Server\AgentMcpServer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\McpServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Bootstraps the package: config, install command, transport registration, the
 * agent-mcp throttle limiter, and publishable agent assets.
 *
 * Route registration is config-gated: when both enabled and
 * auto_register are true the HTTP + stdio transports are wired at boot; when
 * auto_register is false the package registers nothing and the customer wires the
 * server manually in routes/ai.php. enabled is the master kill switch.
 */
class AgentMcpServiceProvider extends PackageServiceProvider
{
    /**
     * Tool flags retired in 2.0, mapped to the tool that replaced each one and what
     * that tool's flag opens. Used only to word the warning: these keys are never read
     * as aliases, because a 1.x key silently gating a 2.0 tool would be a hidden shim.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array RETIRED_TOOL_KEYS = [
        'list_routes' => ['routes', 'modes list and detail'],
        'inspect_route' => ['routes', 'modes list and detail'],
        'queue_backlog' => ['queue_status', 'sections backlog, failed, horizon'],
        'queue_failed_jobs' => ['queue_status', 'sections backlog, failed, horizon'],
        'horizon_status' => ['queue_status', 'sections backlog, failed, horizon'],
        'db_index_health' => ['db_health', 'sections indexes, missing_fk, sizes'],
        'db_missing_fk_indexes' => ['db_health', 'sections indexes, missing_fk, sizes'],
        'db_table_sizes' => ['db_health', 'sections indexes, missing_fk, sizes'],
        'db_slow_queries' => ['db_diagnostics', 'sections slow_queries, locks; default OFF'],
        'db_active_locks' => ['db_diagnostics', 'sections slow_queries, locks; default OFF'],
        'cache_status' => ['cache', 'modes status and inspect'],
        'cache_inspect' => ['cache', 'modes status and inspect'],
        'app_about' => ['app_info', 'sections about, schedule, events, storage, env_keys'],
        'schedule_list' => ['app_info', 'sections about, schedule, events, storage, env_keys'],
        'event_list' => ['app_info', 'sections about, schedule, events, storage, env_keys'],
        'storage_info' => ['app_info', 'sections about, schedule, events, storage, env_keys'],
        'env_keys' => ['app_info', 'sections about, schedule, events, storage, env_keys'],
    ];

    public function configurePackage(Package $package): void
    {
        $package
            ->name('agent-mcp')
            ->hasConfigFile()
            ->hasCommand(InstallCommand::class)
            ->hasCommand(StdioBridgeCommand::class)
            ->hasCommand(CallToolCommand::class)
            ->hasCommand(ListToolsCommand::class)
            ->hasCommand(ToolSchemaCommand::class);
    }

    public function packageRegistered(): void
    {
        // laravel/mcp's McpServiceProvider binds the Mcp registrar facade and the
        // resolving(Request::class) callback the tools rely on. It is auto-discovered
        // in a real app; register it explicitly so the isolated testbench app (and any
        // app that has not discovered it yet) can resolve Mcp::web()/Mcp::local() and
        // the injected Laravel\Mcp\Request. register() is idempotent.
        $this->app->register(McpServiceProvider::class);
    }

    public function packageBooted(): void
    {
        // 1. The throttle:agent-mcp limiter the default middleware references. Defined
        //    unconditionally so a customer who wires routes manually (auto_register=false)
        //    still gets a working limiter.
        $this->registerRateLimiter();

        // 1b. Ensure the audit channel exists. Audit is on by default and a headline
        //     feature; without a defined channel Laravel's LogManager would silently
        //     fall back to the emergency logger (stderr) on every tool call. Define a
        //     sane default only when the operator has not configured the channel.
        $this->registerAuditChannel();

        // 2. Publish the agent assets (config is handled by hasConfigFile; this is the
        //    client config example). Console-only, matching the
        //    framework convention; the path map is lazy.
        $this->registerPublishing();

        // 2b. Tell the operator about 1.x tool flags in a published config: they are
        //     ignored now, and a disabled one no longer hides what it used to gate.
        $this->warnAboutRetiredToolKeys();

        // 3. Config-gated transport registration.
        if (! config('agent-mcp.enabled') || ! config('agent-mcp.auto_register')) {
            return;
        }

        $this->registerTransports();
    }

    /**
     * Register a default file-backed audit channel when the operator has not defined
     * one. Keeps the audit trail (a stated security feature) working out of the box
     * instead of degrading to the emergency logger. The operator can override by
     * defining the channel under logging.channels, or point audit.channel elsewhere.
     */
    private function registerAuditChannel(): void
    {
        $channel = config('agent-mcp.audit.channel');

        if (! is_string($channel) || $channel === '') {
            return;
        }

        if (config("logging.channels.{$channel}") !== null) {
            return;
        }

        config([
            "logging.channels.{$channel}" => [
                'driver' => 'single',
                'path' => storage_path('logs/agent-mcp-audit.log'),
                'level' => 'info',
                'replace_placeholders' => true,
            ],
        ]);
    }

    /**
     * Define the throttle:agent-mcp rate limiter referenced by the default route
     * middleware. Keyed by a sha1 FINGERPRINT of the presented key, not the client
     * IP: behind the stdio bridge or a proxy every caller collapses to
     * one IP, so an IP bucket would be useless. The raw key is never used as the
     * bucket key, only its sha1 fingerprint. Requests with no presented key fall into
     * a shared 'unauthenticated' bucket (the middleware rejects them anyway). Mirrors
     * KeyAuthMiddleware's key extraction so the bucket tracks the same credential the
     * auth layer checks.
     */
    private function registerRateLimiter(): void
    {
        RateLimiter::for('agent-mcp', function (Request $request): Limit {
            $presented = $this->presentedKey($request);

            return is_string($presented) && $presented !== ''
                ? Limit::perMinute(60)->by(sha1($presented))
                : Limit::perMinute(60)->by('unauthenticated');
        });
    }

    /**
     * Read the presented key the same way KeyAuthMiddleware does: the Authorization
     * header is parsed strictly via bearerToken(); any other configured header is read
     * as its raw value. Returns null when nothing usable was presented.
     */
    private function presentedKey(Request $request): ?string
    {
        $header = config('agent-mcp.key_header', 'Authorization');

        if (is_string($header) && strcasecmp($header, 'Authorization') === 0) {
            return $request->bearerToken();
        }

        return is_string($header) ? $request->header($header) : null;
    }

    /**
     * Register the HTTP and stdio transports, each gated by its transport flag.
     * HTTP carries the configured middleware (KeyAuthMiddleware + throttle) so the
     * route is never reachable without the server-admin key.
     */
    private function registerTransports(): void
    {
        if (config('agent-mcp.transports.http')) {
            Mcp::web(config('agent-mcp.route'), AgentMcpServer::class)
                ->middleware(config('agent-mcp.middleware'));
        }

        if (config('agent-mcp.transports.stdio')) {
            Mcp::local('agent-mcp', AgentMcpServer::class);
        }
    }

    /**
     * Log one warning per console boot listing every retired tool key still present in
     * agent-mcp.tools. Console only, so an operator sees it on deploy or artisan runs
     * while HTTP requests stay quiet. The keys are reported, never honoured: the
     * replacement flag alone governs every section the old keys used to split.
     */
    private function warnAboutRetiredToolKeys(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $tools = config('agent-mcp.tools');

        if (! is_array($tools)) {
            return;
        }

        $notes = [];

        foreach (array_intersect_key(self::RETIRED_TOOL_KEYS, $tools) as $key => [$tool, $opens]) {
            $notes[] = "{$key} => `{$tool}` ({$opens})";
        }

        if ($notes === []) {
            return;
        }

        Log::warning(
            'agent-mcp: agent-mcp.tools holds keys retired in 2.0, which are ignored: '
            .implode('; ', $notes)
            .'. Each replacement flag now governs every listed section; set it in your published '
            .'config/agent-mcp.php and remove the retired keys.',
        );
    }

    /**
     * Register the publishable agent assets (client config example). The stub
     * file path map is lazy, so referencing it here
     * is safe before it exists.
     */
    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../resources/stubs/mcp.json.example.stub' => base_path('.mcp.json.example'),
        ], 'agent-mcp-assets');
    }
}
