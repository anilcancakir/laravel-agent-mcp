<?php

// Config defaults are loaded by TestCase::defineEnvironment() from config/agent-mcp.php.
// Tests here assert that the resolved defaults match the package's contract; they do not
// manually reload the file — the harness already does that at boot.

it('resolves readonly as the default db connection', function (): void {
    expect(config('agent-mcp.connection'))->toBe('readonly');
});

it('disables run_artisan by default to prevent confused-deputy command execution', function (): void {
    expect(config('agent-mcp.tools.run_artisan'))->toBeFalse();
});

it('ships an empty artisan allowlist so the tool is effectively off until explicitly configured', function (): void {
    expect(config('agent-mcp.artisan.allowlist'))->toBe([]);
});

it('enables the package and auto_register by default', function (): void {
    expect(config('agent-mcp.enabled'))->toBeTrue();
    expect(config('agent-mcp.auto_register'))->toBeTrue();
});

it('ships auth:sanctum and throttle middleware by default', function (): void {
    expect(config('agent-mcp.middleware'))->toBe(['auth:sanctum', 'throttle:agent-mcp']);
});

it('ships the correct sanctum ability names', function (): void {
    expect(config('agent-mcp.abilities.read'))->toBe('agent-mcp:read');
    expect(config('agent-mcp.abilities.artisan'))->toBe('agent-mcp:artisan');
});

it('enables all read tools and disables run_artisan by default', function (): void {
    expect(config('agent-mcp.tools.db_schema'))->toBeTrue();
    expect(config('agent-mcp.tools.db_query'))->toBeTrue();
    expect(config('agent-mcp.tools.db_raw_select'))->toBeTrue();
    expect(config('agent-mcp.tools.read_logs'))->toBeTrue();
    expect(config('agent-mcp.tools.run_artisan'))->toBeFalse();
});

it('caps query rows at 100 and sets a 5s statement timeout against DoS', function (): void {
    expect(config('agent-mcp.query.max_rows'))->toBe(100);
    expect(config('agent-mcp.query.statement_timeout_ms'))->toBe(5000);
});

it('enables redaction and audit by default as defense-in-depth', function (): void {
    expect(config('agent-mcp.redaction.enabled'))->toBeTrue();
    expect(config('agent-mcp.audit.enabled'))->toBeTrue();
    expect(config('agent-mcp.audit.channel'))->toBe('agent-mcp-audit');
});

it('ships default redaction patterns covering common secret shapes', function (): void {
    $patterns = config('agent-mcp.redaction.patterns');

    expect($patterns)->toBeArray()->not()->toBeEmpty();
});

it('defaults logs channel to null so the active channel is resolved at runtime', function (): void {
    expect(config('agent-mcp.logs.channel'))->toBeNull();
    expect(config('agent-mcp.logs.max_lines'))->toBe(200);
});
