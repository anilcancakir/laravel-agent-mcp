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

it('defaults domain to null so the endpoint accepts any host', function (): void {
    expect(config('agent-mcp'))->toHaveKey('domain');
    expect(config('agent-mcp.domain'))->toBeNull();
});

it('reads the native endpoint settings from the environment', function (): void {
    $environment = [
        'AGENT_MCP_PATH' => 'private/7f9c2a',
        'AGENT_MCP_DOMAIN' => 'agents.example.test',
    ];
    $previous = [];

    foreach ($environment as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($name.'='.$value);
    }

    try {
        $configuration = require dirname(__DIR__, 2).'/config/agent-mcp.php';

        expect($configuration['route'])->toBe('private/7f9c2a');
        expect($configuration['domain'])->toBe('agents.example.test');
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
    }
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

it('caps query rows at 50 and sets a 5s statement timeout against DoS', function (): void {
    expect(config('agent-mcp.query.max_rows'))->toBe(50);
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
    expect(config('agent-mcp.logs.max_lines'))->toBe(100);
});

// Tool enable flags

it('ships exactly the fourteen tool flags with their default states', function (): void {
    expect(config('agent-mcp.tools'))->toBe([
        'db_schema' => true,
        'db_query' => true,
        'db_raw_select' => true,
        'read_logs' => true,
        'run_artisan' => false,
        'migrations_status' => true,
        'db_health' => true,
        'db_diagnostics' => false,
        'queue_status' => true,
        'cache' => true,
        'cache_keys' => false,
        'routes' => true,
        'app_info' => true,
        'config_inspect' => false,
    ]);
});

it('enables safe read-only investigation tools by default', function (): void {
    expect(config('agent-mcp.tools.migrations_status'))->toBeTrue();
    expect(config('agent-mcp.tools.db_health'))->toBeTrue();
    expect(config('agent-mcp.tools.queue_status'))->toBeTrue();
    expect(config('agent-mcp.tools.cache'))->toBeTrue();
    expect(config('agent-mcp.tools.routes'))->toBeTrue();
    expect(config('agent-mcp.tools.app_info'))->toBeTrue();
});

it('disables sensitive investigation tools by default so the operator opts in', function (): void {
    // Privileged DB reads require pg_monitor/pg_read_all_stats or performance_schema grants.
    expect(config('agent-mcp.tools.db_diagnostics'))->toBeFalse();

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
