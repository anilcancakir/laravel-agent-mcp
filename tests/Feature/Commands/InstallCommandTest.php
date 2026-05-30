<?php

use function Pest\Laravel\artisan;

// agent-mcp:install publishes config + assets and prints key-auth guidance:
//   (1) AGENT_MCP_KEY generation hint (mandatory env key, fail-closed),
//   (2) per-engine readonly DB user setup (also works on the default connection),
//   (3) HTTP client .mcp.json block with Bearer auth,
//   (4) stdio bridge .mcp.json block with AGENT_MCP_URL + AGENT_MCP_KEY env,
//   (5) claude mcp add one-liner for the http endpoint,
//   (6) app.debug=true security warning.
// The command MUST NOT print a real key or reference Sanctum.

it('exits cleanly', function (): void {
    artisan('agent-mcp:install')->assertOk();
});

it('prints the AGENT_MCP_KEY generation hint', function (): void {
    artisan('agent-mcp:install')
        ->expectsOutputToContain('AGENT_MCP_KEY')
        ->expectsOutputToContain('bin2hex(random_bytes(32))')
        ->assertOk();
});

it('prints the HTTP client block with Bearer auth', function (): void {
    artisan('agent-mcp:install')
        ->expectsOutputToContain('"type": "http"')
        ->expectsOutputToContain('Bearer')
        ->assertOk();
});

it('prints the stdio bridge block with AGENT_MCP_URL env', function (): void {
    artisan('agent-mcp:install')
        ->expectsOutputToContain('agent-mcp:stdio')
        ->expectsOutputToContain('AGENT_MCP_URL')
        ->assertOk();
});

it('prints the agent-mcp route in the HTTP block', function (): void {
    artisan('agent-mcp:install')
        ->expectsOutputToContain('agent-mcp')
        ->assertOk();
});

it('prints the claude mcp add one-liner', function (): void {
    artisan('agent-mcp:install')
        ->expectsOutputToContain('claude mcp add')
        ->assertOk();
});

it('prints the app.debug warning', function (): void {
    artisan('agent-mcp:install')
        ->expectsOutputToContain('app.debug')
        ->assertOk();
});

it('prints per-engine readonly DB user reminders for MySQL, PostgreSQL, and SQLite', function (): void {
    artisan('agent-mcp:install')
        ->expectsOutputToContain('MySQL')
        ->expectsOutputToContain('PostgreSQL')
        ->expectsOutputToContain('SQLite')
        ->assertOk();
});

it('prints the app URL in the HTTP block', function (): void {
    config()->set('app.url', 'https://example.test');
    config()->set('agent-mcp.route', 'agent-mcp');

    artisan('agent-mcp:install')
        ->expectsOutputToContain('https://example.test/agent-mcp')
        ->assertOk();
});

it('does not reference Sanctum or createToken', function (): void {
    artisan('agent-mcp:install')
        ->assertOk();

    // Confirm legacy Sanctum terms are absent by grepping the command source: the
    // command must carry no createToken/Sanctum/abilities language under key auth.
    expect(file_get_contents(__DIR__.'/../../../src/Commands/InstallCommand.php'))
        ->not->toContain('createToken')
        ->not->toContain('sanctum')
        ->not->toContain('Sanctum')
        ->not->toContain('abilities');
});
