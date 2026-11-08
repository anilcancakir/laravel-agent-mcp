<?php

use Anilcancakir\LaravelAgentMcp\Tools\AppInfoTool;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only AppInfoTool, keeping these tests isolated
// from AgentMcpServer.

/**
 * Inline stub server that hosts AppInfoTool for this test file only.
 */
final class AppInfoStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        AppInfoTool::class,
    ];
}

// Recognizable values that MUST NEVER appear in the output: the env section
// emits key NAMES only and the storage section strips disk credentials.
const APP_INFO_ENV_SECRET = 'ENV_VALUE_NEVER_LEAKS_4242';
const APP_INFO_DISK_SECRET = 'AWS_SECRET_NEVER_LEAKS_7777';
const APP_INFO_DISK_KEY = 'AWS_KEY_NEVER_LEAKS_8888';

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.app_info', true);
    config()->set('agent-mcp.audit.enabled', false);

    $_ENV['AGENT_MCP_TEST_ENV_KEY'] = APP_INFO_ENV_SECRET;

    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => APP_INFO_DISK_KEY,
        'secret' => APP_INFO_DISK_SECRET,
        'token' => 'a-session-token',
        'password' => 'a-disk-password',
        'region' => 'eu-west-1',
        'bucket' => 'app-bucket',
        'root' => '',
        'visibility' => 'private',
    ]);

    $schedule = app(Schedule::class);
    $schedule->command('inspire')->daily()->description('Test inspire command');
    $schedule->call(fn () => null)->cron('* * * * *')->description('Test closure callback');

    Event::listen('agent-test.event', fn () => null);
    Event::listen('agent-test.*', fn () => null);
});

afterEach(function (): void {
    unset($_ENV['AGENT_MCP_TEST_ENV_KEY']);
});

// --- tool-enabled gate and metadata ---

it('denies the call when app_info is disabled in config', function (): void {
    config()->set('agent-mcp.tools.app_info', false);

    AppInfoStubServer::tool(AppInfoTool::class, [])
        ->assertHasErrors();

    AppInfoStubServer::tool(AppInfoTool::class, ['section' => 'env_keys'])
        ->assertHasErrors();
});

it('is annotated read-only and named app_info', function (): void {
    $tool = app(AppInfoTool::class);

    expect($tool->name())->toBe('app_info')
        ->and($tool->toArray()['annotations'])->toHaveKey('readOnlyHint', true)
        ->and(mb_strlen($tool->description()))->toBeLessThanOrEqual(1500);
});

// --- about (default) ---

it('defaults to the about section with all five parts', function (): void {
    AppInfoStubServer::tool(AppInfoTool::class, [])
        ->assertOk()
        ->assertSee('"environment"')
        ->assertSee('php_version')
        ->assertSee('laravel_version')
        ->assertSee('configuration_cached')
        ->assertSee('routes_cached')
        ->assertSee('events_cached')
        ->assertSee('"drivers"')
        ->assertSee('"opcache"')
        ->assertSee('"extensions"');
});

it('returns only the requested parts of the about section', function (): void {
    AppInfoStubServer::tool(AppInfoTool::class, ['section' => 'about', 'parts' => ['environment']])
        ->assertOk()
        ->assertSee('php_version')
        ->assertDontSee('"drivers"')
        ->assertDontSee('"extensions"');
});

it('reports driver names in the drivers part', function (): void {
    config()->set('cache.default', 'array');
    config()->set('queue.default', 'sync');

    AppInfoStubServer::tool(AppInfoTool::class, ['parts' => ['drivers']])
        ->assertOk()
        ->assertSee('"cache":"array"')
        ->assertSee('"queue":"sync"')
        ->assertSee('"database"');
});

it('lists loaded PHP extensions in the extensions part', function (): void {
    AppInfoStubServer::tool(AppInfoTool::class, ['parts' => ['extensions']])
        ->assertOk()
        ->assertSee('Core');
});

// --- env_keys ---

it('returns env key names only and never a value', function (): void {
    AppInfoStubServer::tool(AppInfoTool::class, ['section' => 'env_keys'])
        ->assertOk()
        ->assertSee('AGENT_MCP_TEST_ENV_KEY')
        ->assertSee('"count":')
        ->assertDontSee(APP_INFO_ENV_SECRET);
});

// --- storage ---

it('strips key, secret, password and token from disk configs', function (): void {
    AppInfoStubServer::tool(AppInfoTool::class, ['section' => 'storage'])
        ->assertOk()
        ->assertSee('"s3"')
        ->assertSee('eu-west-1')
        ->assertSee('visibility')
        ->assertSee('"links"')
        ->assertDontSee(APP_INFO_DISK_KEY)
        ->assertDontSee(APP_INFO_DISK_SECRET)
        ->assertDontSee('a-session-token')
        ->assertDontSee('a-disk-password');
});

// --- schedule ---

it('lists scheduled events with their timing and flag keys', function (): void {
    AppInfoStubServer::tool(AppInfoTool::class, ['section' => 'schedule'])
        ->assertOk()
        ->assertSee('inspire')
        ->assertSee('Test inspire command')
        ->assertSee('"expression"')
        ->assertSee('next_run')
        ->assertSee('without_overlapping')
        ->assertSee('on_one_server')
        ->assertSee('even_in_maintenance');
});

it('reports the closure file and line for a closure schedule', function (): void {
    AppInfoStubServer::tool(AppInfoTool::class, ['section' => 'schedule'])
        ->assertOk()
        ->assertSee('Test closure callback')
        ->assertSee('AppInfoToolTest.php:');
});

// --- events ---

it('lists registered listeners including wildcard listeners', function (): void {
    AppInfoStubServer::tool(AppInfoTool::class, ['section' => 'events'])
        ->assertOk()
        ->assertSee('agent-test.event')
        ->assertSee('agent-test.*')
        ->assertSee('"listeners"')
        ->assertSee('"closure"');
});

it('narrows the events section by a filter substring', function (): void {
    AppInfoStubServer::tool(AppInfoTool::class, ['section' => 'events', 'filter' => 'agent-test.event'])
        ->assertOk()
        ->assertSee('agent-test.event')
        ->assertDontSee('agent-test.*');
});

// --- section validation ---

it('rejects an invalid section with a message naming every valid section', function (): void {
    AppInfoStubServer::tool(AppInfoTool::class, ['section' => 'bogus'])
        ->assertHasErrors()
        ->assertSee('about')
        ->assertSee('env_keys')
        ->assertSee('storage')
        ->assertSee('schedule')
        ->assertSee('events')
        ->assertDontSee('bogus');
});
