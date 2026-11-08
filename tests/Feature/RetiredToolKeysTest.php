<?php

use Anilcancakir\LaravelAgentMcp\AgentMcpServiceProvider;
use Anilcancakir\LaravelAgentMcp\Support\RetiredToolKeys;
use Anilcancakir\LaravelAgentMcp\Tools\CacheTool;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Registrar;
use Spatie\LaravelPackageTools\Package;

// A consumer's published config from 1.x still carries the 25 old tool keys. 2.0 never
// reads them as aliases; instead agent-mcp:install and agent-mcp:tools print one aggregated
// warning so the operator learns which new flag now governs the sections the old key gated.
// The warning never goes to the application log: read_logs tails that file, and a
// warning written on every console boot would fill it.

it('words one warning naming a retired key, its replacement and the sections it opens', function (): void {
    config()->set('agent-mcp.tools.cache_inspect', false);

    expect(RetiredToolKeys::warning())
        ->toContain('cache_inspect')
        ->toContain('`cache`')
        ->toContain('status')
        ->toContain('inspect');
});

it('aggregates several retired keys into a single warning', function (): void {
    config()->set('agent-mcp.tools.list_routes', true);
    config()->set('agent-mcp.tools.db_slow_queries', false);
    config()->set('agent-mcp.tools.env_keys', false);

    $warning = RetiredToolKeys::warning();

    expect(substr_count((string) $warning, 'agent-mcp.tools holds keys retired in 2.0'))->toBe(1);
    expect($warning)
        ->toContain('list_routes')
        ->toContain('`routes`')
        ->toContain('db_slow_queries')
        ->toContain('`db_diagnostics`')
        ->toContain('slow_queries')
        ->toContain('env_keys')
        ->toContain('`app_info`');
});

it('has no warning when the config carries no retired key', function (): void {
    expect(RetiredToolKeys::warning())->toBeNull();
});

it('has no warning when the tools config is not an array', function (): void {
    config()->set('agent-mcp.tools', null);

    expect(RetiredToolKeys::warning())->toBeNull();
});

it('writes nothing to the application log on boot, whatever the config holds', function (): void {
    $log = Log::spy();
    config()->set('agent-mcp.tools.cache_inspect', false);

    app()->instance(Registrar::class, new Registrar);

    $provider = new AgentMcpServiceProvider(app());
    $provider->configurePackage(new Package);
    $provider->packageBooted();

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
