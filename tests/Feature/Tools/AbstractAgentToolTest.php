<?php

declare(strict_types=1);

use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubAgentServer;
use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubAgentTool;
use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubNoopRegisterTool;
use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubTokenUser;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Sanctum\TransientToken;

/**
 * A first-party session principal: carries the ability via tokenCan() but whose
 * current access token is a TransientToken (Sanctum session auth), which can() would
 * grant for any ability. authorize() must reject it so session auth cannot bypass
 * ability scoping.
 */
class SessionStubUser extends StubTokenUser
{
    public function currentAccessToken(): ?object
    {
        return new TransientToken;
    }
}

// AbstractAgentTool is the authorization hub: the authoritative ability + tool-enabled
// check lives in authorize() (called at the top of every subclass handle()), independent
// of shouldRegister (best-effort UX only) and independent of any request-passed user
// (it reads the route's auth guard). These tests prove the deny path fires in handle()
// even when shouldRegister is a no-op, plus the audit/redaction pipeline wiring.

beforeEach(function (): void {
    // laravel/mcp's own provider registers the resolving(Request::class) callback that
    // populates the injected Request from the bound mcp.request. In production it is
    // auto-discovered; the isolated package test app does not load it (no provider until
    // Step 14), so register it here to exercise the real method-injection contract.
    app()->register(McpServiceProvider::class);

    // The stub tools read these config keys via the base; set them explicitly so the
    // test is self-contained regardless of the published defaults.
    config()->set('agent-mcp.abilities.read', 'agent-mcp:read');
    config()->set('agent-mcp.tools.stub-agent-tool', true);
    config()->set('agent-mcp.tools.stub-noop-register-tool', true);
});

it('denies in handle() when the token lacks the required ability (authoritative)', function (): void {
    $user = new StubTokenUser(id: 1, abilities: []);

    StubAgentServer::actingAs($user)
        ->tool(StubAgentTool::class, [])
        ->assertHasErrors();
});

it('denies in handle() even when shouldRegister is a no-op returning true', function (): void {
    // StubNoopRegisterTool::shouldRegister() always returns true, so registration UX
    // cannot be the boundary. handle() must still deny a token without the ability.
    $user = new StubTokenUser(id: 1, abilities: []);

    StubAgentServer::actingAs($user)
        ->tool(StubNoopRegisterTool::class, [])
        ->assertHasErrors();
});

it('denies in handle() when no user is authenticated on the guard', function (): void {
    StubAgentServer::tool(StubAgentTool::class, [])
        ->assertHasErrors();
});

it('handles successfully and records an audit entry when the token has the ability', function (): void {
    $captured = null;

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$captured): bool {
        $captured = $context;

        return $message === 'mcp.tool_invoked';
    });

    $user = new StubTokenUser(id: 42, abilities: ['agent-mcp:read']);

    StubAgentServer::actingAs($user)
        ->tool(StubAgentTool::class, ['needle' => 'value'])
        ->assertOk()
        ->assertSee('handled');

    expect($captured)->not->toBeNull();
    expect($captured['tool'])->toBe('stub-agent-tool');
    expect($captured['arg_shape'])->toBe(['needle' => 'string']);
    expect($captured['user_id'])->toBe(42);
});

it('denies in handle() when the tool is disabled in config even with a valid ability', function (): void {
    config()->set('agent-mcp.tools.stub-agent-tool', false);

    $user = new StubTokenUser(id: 7, abilities: ['agent-mcp:read']);

    StubAgentServer::actingAs($user)
        ->tool(StubAgentTool::class, [])
        ->assertHasErrors();
});

it('runs tool output through the redactor before returning', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', true);

    $user = new StubTokenUser(id: 9, abilities: ['agent-mcp:read']);

    // The stub tool emits a string containing an email; the configured redactor must
    // replace it with the marker before the response leaves handle().
    StubAgentServer::actingAs($user)
        ->tool(StubAgentTool::class, ['leak' => 'contact me at secret@example.com please'])
        ->assertOk()
        ->assertSee('[REDACTED]')
        ->assertDontSee('secret@example.com');
});

it('denies in handle() when the required ability config resolves to empty (fail closed)', function (): void {
    // A misconfigured/missing ability key must DENY, never resolve to '' which a
    // wildcard ('*') token would be granted.
    config()->set('agent-mcp.abilities.read', null);

    $user = new StubTokenUser(id: 1, abilities: ['*', 'agent-mcp:read']);

    StubAgentServer::actingAs($user)
        ->tool(StubAgentTool::class, [])
        ->assertHasErrors();
});

it('denies in handle() for a session (TransientToken) principal even with the ability', function (): void {
    // Sanctum first-party session auth yields a TransientToken whose can() returns true
    // for every ability. The agent must use a scoped personal access token; reject it.
    $user = new SessionStubUser(id: 1, abilities: ['agent-mcp:read']);

    StubAgentServer::actingAs($user)
        ->tool(StubAgentTool::class, [])
        ->assertHasErrors();
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
