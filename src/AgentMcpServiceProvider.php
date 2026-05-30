<?php

namespace Anilcancakir\LaravelAgentMcp;

use Anilcancakir\LaravelAgentMcp\Authorization\SanctumTokenAuthorizer;
use Anilcancakir\LaravelAgentMcp\Commands\InstallCommand;
use Anilcancakir\LaravelAgentMcp\Contracts\AuthorizesAgentTools;
use Anilcancakir\LaravelAgentMcp\Server\AgentMcpServer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\McpServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Bootstraps the package: config, install command, transport registration, the
 * agent-mcp throttle limiter, and publishable agent assets.
 *
 * Route registration is config-gated (Oracle IMP3): when both enabled and
 * auto_register are true the HTTP + stdio transports are wired at boot; when
 * auto_register is false the package registers nothing and the customer wires the
 * server manually in routes/ai.php. enabled is the master kill switch.
 */
class AgentMcpServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('agent-mcp')
            ->hasConfigFile()
            ->hasCommand(InstallCommand::class);
    }

    public function packageRegistered(): void
    {
        // laravel/mcp's McpServiceProvider binds the Mcp registrar facade and the
        // resolving(Request::class) callback the tools rely on. It is auto-discovered
        // in a real app; register it explicitly so the isolated testbench app (and any
        // app that has not discovered it yet) can resolve Mcp::web()/Mcp::local() and
        // the injected Laravel\Mcp\Request. register() is idempotent.
        $this->app->register(McpServiceProvider::class);

        // Bind the pluggable ability authorizer from config (default: the Sanctum
        // token authorizer). A host without Sanctum points config('agent-mcp.authorizer')
        // at its own implementation, so the tools carry no hard auth-package dependency.
        $this->app->bind(AuthorizesAgentTools::class, function ($app): AuthorizesAgentTools {
            $class = config('agent-mcp.authorizer', SanctumTokenAuthorizer::class);

            return $app->make($class);
        });
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

        // 2. Publish the agent assets (config is handled by hasConfigFile; these are the
        //    AGENTS.md snippet + client config example authored in Step 16). Console-only,
        //    matching the framework convention; the path maps are lazy.
        $this->registerPublishing();

        // 3. Config-gated transport registration (Oracle IMP3).
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
     * middleware. Keyed by the authenticated user when present, falling back to the
     * client IP for the (middleware-rejected) unauthenticated case.
     */
    private function registerRateLimiter(): void
    {
        RateLimiter::for('agent-mcp', function (Request $request): Limit {
            $user = $request->user();

            return $user !== null
                ? Limit::perMinute(60)->by((string) $user->getAuthIdentifier())
                : Limit::perMinute(60)->by((string) $request->ip());
        });
    }

    /**
     * Register the HTTP and stdio transports, each gated by its transport flag.
     * HTTP carries the configured middleware (auth:sanctum + throttle) so the route
     * is never reachable unauthenticated.
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
     * Register the publishable agent assets (AGENTS.md snippet + client config
     * example). The stub files are authored in Step 16; the path map is lazy, so
     * referencing them here is safe before they exist.
     */
    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../resources/stubs/AGENTS.md.stub' => base_path('AGENTS.md'),
            __DIR__.'/../resources/stubs/mcp.json.example.stub' => base_path('.mcp.json.example'),
        ], 'agent-mcp-assets');
    }
}
