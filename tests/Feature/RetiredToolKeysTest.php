<?php

use Anilcancakir\LaravelAgentMcp\AgentMcpServiceProvider;
use Anilcancakir\LaravelAgentMcp\Tools\CacheTool;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Registrar;
use Mockery\MockInterface;
use Spatie\LaravelPackageTools\Package;

// A consumer's published config from 1.x still carries the 25 old tool keys. 2.0 never
// reads them as aliases; instead a console boot logs one aggregated warning so the
// operator learns which new flag now governs the sections the old key used to gate.

/**
 * Boot the provider again against the current config, on a clean Mcp registrar so the
 * repeated transport registration does not collide with the one from the app boot.
 */
function bootAgentMcpProviderAgain(): void
{
    app()->instance(Registrar::class, new Registrar);

    $provider = new AgentMcpServiceProvider(app());
    $provider->configurePackage(new Package);
    $provider->packageBooted();
}

/**
 * Collect every message the Log facade spy received as a warning.
 *
 * @return array<int, string>
 */
function loggedWarnings(MockInterface $log): array
{
    $messages = [];

    $log->shouldHaveReceived('warning')->withArgs(function (string $message) use (&$messages): bool {
        $messages[] = $message;

        return true;
    });

    return $messages;
}

it('logs one warning naming a retired key, its replacement and the sections it opens', function (): void {
    $log = Log::spy();
    config()->set('agent-mcp.tools.cache_inspect', false);

    bootAgentMcpProviderAgain();

    $warnings = loggedWarnings($log);

    expect($warnings)->toHaveCount(1);
    expect($warnings[0])
        ->toContain('cache_inspect')
        ->toContain('`cache`')
        ->toContain('status')
        ->toContain('inspect');
});

it('aggregates several retired keys into a single warning', function (): void {
    $log = Log::spy();
    config()->set('agent-mcp.tools.list_routes', true);
    config()->set('agent-mcp.tools.db_slow_queries', false);
    config()->set('agent-mcp.tools.env_keys', false);

    bootAgentMcpProviderAgain();

    $warnings = loggedWarnings($log);

    expect($warnings)->toHaveCount(1);
    expect($warnings[0])
        ->toContain('list_routes')
        ->toContain('`routes`')
        ->toContain('db_slow_queries')
        ->toContain('`db_diagnostics`')
        ->toContain('slow_queries')
        ->toContain('env_keys')
        ->toContain('`app_info`');
});

it('logs no warning when the config carries no retired key', function (): void {
    $log = Log::spy();

    bootAgentMcpProviderAgain();

    $log->shouldNotHaveReceived('warning');
});

it('logs no warning outside the console even with a retired key present', function (): void {
    $log = Log::spy();
    config()->set('agent-mcp.tools.cache_inspect', false);

    // Testbench always runs in the console; pin the application's cached console state so
    // the provider sees an HTTP boot. The app instance is rebuilt for every test.
    (function (): void {
        $this->isRunningInConsole = false;
    })->call(app());

    expect(app()->runningInConsole())->toBeFalse();

    bootAgentMcpProviderAgain();

    $log->shouldNotHaveReceived('warning');
});

it('never reads a retired key as an alias for the new flag', function (): void {
    config()->set('agent-mcp.tools.cache_inspect', true);
    config()->set('agent-mcp.tools.cache', false);

    expect(app(CacheTool::class)->shouldRegister())->toBeFalse();

    config()->set('agent-mcp.tools.cache_status', false);
    config()->set('agent-mcp.tools.cache', true);

    expect(app(CacheTool::class)->shouldRegister())->toBeTrue();
});
