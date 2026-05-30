<?php

use Anilcancakir\LaravelAgentMcp\Tools\EventListTool;
use Illuminate\Support\Facades\Event;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server whose only registered tool is EventListTool, keeping these
// tests isolated from AgentMcpServer.

/**
 * Inline stub server that hosts EventListTool for this test file only.
 */
final class EventListStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        EventListTool::class,
    ];
}

beforeEach(function (): void {
    // laravel/mcp's own provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.event_list', true);
    config()->set('agent-mcp.audit.enabled', false);

    // Register a regular listener and a wildcard listener for testing.
    Event::listen('agent-test.event', fn () => null);
    Event::listen('agent-test.*', fn () => null);
});

// --- tool-enabled gate ---

it('denies the call when event_list is disabled in config', function (): void {
    config()->set('agent-mcp.tools.event_list', false);

    EventListStubServer::tool(EventListTool::class, [])
        ->assertHasErrors();
});

// --- basic listing ---

it('returns a list of registered event listeners', function (): void {
    $response = EventListStubServer::tool(EventListTool::class, [])
        ->assertOk();

    $response->assertSee('agent-test.event');
    $response->assertSee('listeners');
});

// --- wildcard listeners ---

it('includes wildcard listeners in the output', function (): void {
    $response = EventListStubServer::tool(EventListTool::class, [])
        ->assertOk();

    $response->assertSee('agent-test.*');
});

// --- listener type classification ---

it('classifies closure listeners by file and line', function (): void {
    $response = EventListStubServer::tool(EventListTool::class, [])
        ->assertOk();

    // Closures are reported with type = closure.
    $response->assertSee('closure');
});

// --- filter arg ---

it('narrows results when a filter substring is provided', function (): void {
    $response = EventListStubServer::tool(EventListTool::class, ['filter' => 'agent-test.event'])
        ->assertOk();

    $response->assertSee('agent-test.event');
    // The wildcard key should not appear when filtered to exact event name.
    $response->assertDontSee('agent-test.*');
});

// --- ShouldQueue detection ---

it('detects ShouldQueue on string listener class names', function (): void {
    // Register a string listener that implements ShouldQueue.
    Event::listen('agent-test.queued', 'NonExistentQueuedListener@handle');

    $response = EventListStubServer::tool(EventListTool::class, ['filter' => 'agent-test.queued'])
        ->assertOk();

    // The event appears in the output (listener type = string).
    $response->assertSee('agent-test.queued');
});
