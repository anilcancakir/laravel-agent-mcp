<?php

// Config defaults are loaded by TestCase::defineEnvironment() from config/agent-mcp.php.
// Tests here assert that the resolved defaults match the package's contract; they do not
// manually reload the file; the harness already does that at boot.

use Anilcancakir\LaravelAgentMcp\Http\Middleware\KeyAuthMiddleware;

it('defaults key to null so the server is fail-closed until the operator sets AGENT_MCP_KEY', function (): void {
    expect(config('agent-mcp.key'))->toBeNull();
});

it('defaults key_header to Authorization matching the Bearer scheme', function (): void {
    expect(config('agent-mcp.key_header'))->toBe('Authorization');
});

it('defaults route to agent-mcp', function (): void {
    expect(config('agent-mcp.route'))->toBe('agent-mcp');
});

it('ships KeyAuthMiddleware and throttle middleware by default', function (): void {
    $middleware = config('agent-mcp.middleware');

    expect($middleware)->toContain(KeyAuthMiddleware::class);
    expect($middleware)->toContain('throttle:agent-mcp');
});

it('does not ship abilities or authorizer keys', function (): void {
    expect(config('agent-mcp'))->not()->toHaveKey('abilities');
    expect(config('agent-mcp'))->not()->toHaveKey('authorizer');
});

it('defaults connection to null so the resolver falls back to the app default', function (): void {
    expect(config('agent-mcp.connection'))->toBeNull();
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

// Investigation tools: per-tool flags

it('enables safe read-only investigation tools by default', function (): void {
    // Queue
    expect(config('agent-mcp.tools.queue_backlog'))->toBeTrue();
    expect(config('agent-mcp.tools.queue_failed_jobs'))->toBeTrue();
    expect(config('agent-mcp.tools.horizon_status'))->toBeTrue();

    // Database health
    expect(config('agent-mcp.tools.db_index_health'))->toBeTrue();
    expect(config('agent-mcp.tools.db_missing_fk_indexes'))->toBeTrue();
    expect(config('agent-mcp.tools.db_table_sizes'))->toBeTrue();
    expect(config('agent-mcp.tools.migrations_status'))->toBeTrue();

    // Cache
    expect(config('agent-mcp.tools.cache_status'))->toBeTrue();
    expect(config('agent-mcp.tools.cache_inspect'))->toBeTrue();

    // App introspection
    expect(config('agent-mcp.tools.list_routes'))->toBeTrue();
    expect(config('agent-mcp.tools.inspect_route'))->toBeTrue();
    expect(config('agent-mcp.tools.app_about'))->toBeTrue();
    expect(config('agent-mcp.tools.schedule_list'))->toBeTrue();
    expect(config('agent-mcp.tools.event_list'))->toBeTrue();
    expect(config('agent-mcp.tools.storage_info'))->toBeTrue();
    expect(config('agent-mcp.tools.env_keys'))->toBeTrue();
});

it('disables sensitive investigation tools by default so the operator opts in', function (): void {
    // Privileged DB tools require pg_monitor/pg_read_all_stats or performance_schema grants.
    expect(config('agent-mcp.tools.db_slow_queries'))->toBeFalse();
    expect(config('agent-mcp.tools.db_active_locks'))->toBeFalse();

    // cache_keys can leak live session IDs from a shared Redis store.
    expect(config('agent-mcp.tools.cache_keys'))->toBeFalse();

    // config_inspect can expose arbitrary application secrets.
    expect(config('agent-mcp.tools.config_inspect'))->toBeFalse();
});

it('keeps the core tool flags unchanged', function (): void {
    expect(config('agent-mcp.tools.db_schema'))->toBeTrue();
    expect(config('agent-mcp.tools.db_query'))->toBeTrue();
    expect(config('agent-mcp.tools.db_raw_select'))->toBeTrue();
    expect(config('agent-mcp.tools.read_logs'))->toBeTrue();
    expect(config('agent-mcp.tools.run_artisan'))->toBeFalse();
});

it('disables cache value reads by default so raw cached data is never exposed without opt-in', function (): void {
    expect(config('agent-mcp.cache.allow_value_read'))->toBeFalse();
});

it('ships a config_inspect block_list covering common secret key patterns', function (): void {
    $blockList = config('agent-mcp.config_inspect.block_list');

    expect($blockList)->toBeArray()->not()->toBeEmpty();

    // Base secret tokens.
    expect($blockList)->toContain('password');
    expect($blockList)->toContain('passwd');
    expect($blockList)->toContain('secret');
    expect($blockList)->toContain('key');
    expect($blockList)->toContain('token');
    expect($blockList)->toContain('auth');
    expect($blockList)->toContain('credential');
    expect($blockList)->toContain('private');

    // DSN/connection-string tokens (embed user:pass@host).
    expect($blockList)->toContain('dsn');
    expect($blockList)->toContain('url');

    // Additional sensitive patterns.
    expect($blockList)->toContain('cipher');
    expect($blockList)->toContain('salt');
    expect($blockList)->toContain('cert');
    expect($blockList)->toContain('pass');
    expect($blockList)->toContain('webhook');
    expect($blockList)->toContain('client_secret');
});

it('ships an empty config_inspect safe_list so no values are revealed without explicit operator configuration', function (): void {
    expect(config('agent-mcp.config_inspect.safe_list'))->toBe([]);
});
