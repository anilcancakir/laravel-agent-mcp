<?php

use Anilcancakir\LaravelAgentMcp\Tools\ScheduleListTool;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server whose only registered tool is ScheduleListTool, keeping these
// tests isolated from AgentMcpServer.

/**
 * Inline stub server that hosts ScheduleListTool for this test file only.
 */
final class ScheduleListStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ScheduleListTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's own provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.schedule_list', true);
    config()->set('agent-mcp.audit.enabled', false);

    // Register a test scheduled event so the tool has something to return.
    // The Schedule singleton persists through the test; resolved fresh per beforeEach.
    $schedule = app(Schedule::class);
    $schedule->command('inspire')->daily()->description('Test inspire command');
    $schedule->call(fn () => null)->cron('* * * * *')->description('Test closure callback');
});

// --- tool-enabled gate ---

it('denies the call when schedule_list is disabled in config', function (): void {
    config()->set('agent-mcp.tools.schedule_list', false);

    ScheduleListStubServer::tool(ScheduleListTool::class, [])
        ->assertHasErrors();
});

// --- basic listing ---

it('returns a list of scheduled events', function (): void {
    $response = ScheduleListStubServer::tool(ScheduleListTool::class, [])
        ->assertOk();

    // The command event registered in beforeEach must appear.
    $response->assertSee('inspire');
    $response->assertSee('expression');
});

// --- next_run field ---

it('includes a next_run timestamp for each event', function (): void {
    $response = ScheduleListStubServer::tool(ScheduleListTool::class, [])
        ->assertOk();

    $response->assertSee('next_run');
});

// --- per-event structure ---

it('includes without_overlapping, on_one_server, even_in_maintenance flags', function (): void {
    $response = ScheduleListStubServer::tool(ScheduleListTool::class, [])
        ->assertOk();

    $response->assertSee('without_overlapping');
    $response->assertSee('on_one_server');
    $response->assertSee('even_in_maintenance');
});

// --- closure event ---

it('lists closure-based (CallbackEvent) events in the schedule', function (): void {
    // The closure registered with a description returns that description as the command
    // summary (getSummaryForDisplay delegates to description when set).
    // The test closure was registered with description "Test closure callback".
    $response = ScheduleListStubServer::tool(ScheduleListTool::class, [])
        ->assertOk();

    $response->assertSee('Test closure callback');
});

// --- description field ---

it('includes the description when set', function (): void {
    $response = ScheduleListStubServer::tool(ScheduleListTool::class, [])
        ->assertOk();

    $response->assertSee('Test inspire command');
});
