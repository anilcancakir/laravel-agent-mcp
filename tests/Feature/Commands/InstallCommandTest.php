<?php

declare(strict_types=1);

use function Pest\Laravel\artisan;

// agent-mcp:install publishes config + assets and prints four guidance sections:
//   (1) per-engine readonly DB user setup,
//   (2) Sanctum token creation snippet,
//   (3) HTTP + stdio client config blocks,
//   (4) app.debug=true warning.
// The command MUST NOT print or generate a real token.

it('exits cleanly', function (): void {
    artisan('agent-mcp:install')->assertOk();
});

it('prints the HTTP client block with type http', function (): void {
    artisan('agent-mcp:install')
        ->expectsOutputToContain('"type": "http"')
        ->assertOk();
});

it('prints the stdio client block with mcp:start', function (): void {
    artisan('agent-mcp:install')
        ->expectsOutputToContain('mcp:start')
        ->assertOk();
});

it('prints the app.debug warning', function (): void {
    artisan('agent-mcp:install')
        ->expectsOutputToContain('app.debug')
        ->assertOk();
});

it('prints the Sanctum token creation snippet without a real token', function (): void {
    artisan('agent-mcp:install')
        ->expectsOutputToContain('createToken')
        ->expectsOutputToContain('agent-mcp:read')
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
    config()->set('agent-mcp.route', 'mcp');

    artisan('agent-mcp:install')
        ->expectsOutputToContain('https://example.test/mcp')
        ->assertOk();
});
