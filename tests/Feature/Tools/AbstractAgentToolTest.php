<?php

use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubAgentServer;
use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubAgentTool;
use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubNoopRegisterTool;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\McpServiceProvider;

// AbstractAgentTool is the tool-enabled hub: authentication is the HTTP layer's job
// (KeyAuthMiddleware verifies the single server-admin key before the route is reached),
// so the only access decision left at the tool is the per-tool enable flag. The
// authoritative check lives in authorize() (called at the top of every subclass
// handle()), independent of shouldRegister (best-effort UX only). These tests prove the
// deny path fires in handle() when the tool is disabled even when shouldRegister is a
// no-op, plus the audit/redaction pipeline wiring (audit records shape only, no identity).

beforeEach(function (): void {
    // laravel/mcp's own provider registers the resolving(Request::class) callback that
    // populates the injected Request from the bound mcp.request. In production it is
    // auto-discovered; the isolated package test app does not load it, so register it
    // here to exercise the real method-injection contract.
    app()->register(McpServiceProvider::class);

    // The stub tools read these config keys via the base; set them explicitly so the
    // test is self-contained regardless of the published defaults.
    config()->set('agent-mcp.tools.stub-agent-tool', true);
    config()->set('agent-mcp.tools.stub-noop-register-tool', true);
});

it('handles successfully and records an audit entry with shape only (no identity)', function (): void {
    $captured = null;

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$captured): bool {
        $captured = $context;

        return $message === 'mcp.tool_invoked';
    });

    StubAgentServer::tool(StubAgentTool::class, ['needle' => 'value'])
        ->assertOk()
        ->assertSee('handled');

    expect($captured)->not->toBeNull();
    expect($captured['tool'])->toBe('stub-agent-tool');
    expect($captured['arg_shape'])->toBe(['needle' => 'string']);
    // No per-caller identity is recorded under the single-key model.
    expect($captured)->not->toHaveKey('user_id');
    expect($captured)->not->toHaveKey('token_id');
});

it('denies in handle() when the tool is disabled in config', function (): void {
    config()->set('agent-mcp.tools.stub-agent-tool', false);

    StubAgentServer::tool(StubAgentTool::class, [])
        ->assertHasErrors();
});

it('denies in handle() even when shouldRegister is a no-op returning true', function (): void {
    // StubNoopRegisterTool::shouldRegister() always returns true, so registration UX
    // cannot be the boundary. handle() must still deny when the tool is disabled.
    config()->set('agent-mcp.tools.stub-noop-register-tool', false);

    StubAgentServer::tool(StubNoopRegisterTool::class, [])
        ->assertHasErrors();
});

it('runs tool output through the redactor before returning', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', true);

    // The stub tool emits a string containing an email; the configured redactor must
    // replace it with the marker before the response leaves handle().
    StubAgentServer::tool(StubAgentTool::class, ['leak' => 'contact me at secret@example.com please'])
        ->assertOk()
        ->assertSee('[REDACTED]')
        ->assertDontSee('secret@example.com');
});

it('answers with compact json that keeps slashes and unicode readable', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    StubAgentServer::tool(StubAgentTool::class, ['mode' => 'sectioned'])
        ->assertOk()
        ->assertSee('{"section":"summary","path":"app/Models/User.php","note":"café","contact":""}');
});

it('keeps the rest of the payload when a value cannot be encoded', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    StubAgentServer::tool(StubAgentTool::class, ['mode' => 'unencodable'])
        ->assertOk()
        ->assertSee('"ratio":0')
        ->assertSee("\u{FFFD}1")
        ->assertSee('"ok":"kept"');
});

it('redacts string leaves of the json payload before encoding', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', true);

    StubAgentServer::tool(StubAgentTool::class, ['mode' => 'sectioned', 'leak' => 'secret@example.com'])
        ->assertOk()
        ->assertSee('[REDACTED]')
        ->assertDontSee('secret@example.com');
});

it('defaults a missing section to the first valid one', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    StubAgentServer::tool(StubAgentTool::class, ['mode' => 'sectioned'])
        ->assertOk()
        ->assertSee('"section":"summary"');
});

it('accepts a valid non-default section', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    StubAgentServer::tool(StubAgentTool::class, ['mode' => 'sectioned', 'section' => 'raw_rows'])
        ->assertOk()
        ->assertSee('"section":"raw_rows"');
});

it('rejects an invalid section with a message naming every valid section but not the submitted value', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    StubAgentServer::tool(StubAgentTool::class, ['mode' => 'sectioned', 'section' => 'bogus'])
        ->assertHasErrors()
        ->assertSee('summary')
        ->assertSee('detail')
        ->assertSee('raw_rows')
        ->assertDontSee('bogus');
});

it('rejects an empty section string with the same message', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    StubAgentServer::tool(StubAgentTool::class, ['mode' => 'sectioned', 'section' => ''])
        ->assertHasErrors()
        ->assertSee('raw_rows');
});

it('audits the resolved section next to the argument shape', function (): void {
    $captured = null;

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$captured): bool {
        $captured = $context;

        return $message === 'mcp.tool_invoked';
    });

    StubAgentServer::tool(StubAgentTool::class, ['mode' => 'sectioned', 'section' => 'detail'])
        ->assertOk();

    expect($captured['section'])->toBe('detail');
    expect($captured['arg_shape'])->toBe(['mode' => 'string', 'section' => 'string']);
});

it('hides the tool via shouldRegister when disabled in config (best-effort UX)', function (): void {
    config()->set('agent-mcp.tools.stub-agent-tool', false);

    $tool = app(StubAgentTool::class);

    expect($tool->eligibleForRegistration())->toBeFalse();
});

it('registers the tool via shouldRegister when enabled in config', function (): void {
    config()->set('agent-mcp.tools.stub-agent-tool', true);

    $tool = app(StubAgentTool::class);

    expect($tool->eligibleForRegistration())->toBeTrue();
});
