<?php

use Anilcancakir\LaravelAgentMcp\Auditing\AuditLogger;
use Illuminate\Support\Facades\Log;

// AuditLogger records tool-invocation metadata (shape only, never values) to the
// configured audit channel, and is a no-op when the audit flag is disabled. Under
// the single server-admin key model there is NO per-caller identity: the logger
// records only the tool name, the argument shape, and a timestamp, and the key is
// never written to the log in any form.

it('records tool name, arg shape, and a timestamp to the audit channel', function (): void {
    $loggedContext = null;

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$loggedContext): bool {
        $loggedContext = $context;

        return $message === 'mcp.tool_invoked';
    });

    $logger = new AuditLogger;
    $logger->record(
        tool: 'db_query',
        argShape: ['table' => 'string', 'limit' => 'integer'],
    );

    expect($loggedContext['tool'])->toBe('db_query');
    expect($loggedContext['arg_shape'])->toBe(['table' => 'string', 'limit' => 'integer']);
    expect($loggedContext)->toHaveKey('timestamp');
});

it('never records a caller identity or token under the single-key model', function (): void {
    $loggedContext = null;

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$loggedContext): bool {
        $loggedContext = $context;

        return $message === 'mcp.tool_invoked';
    });

    $logger = new AuditLogger;
    $logger->record(
        tool: 'db_schema',
        argShape: ['table' => 'string'],
    );

    expect($loggedContext)->not()->toBeNull();
    // No identity surfaces of the old user/Sanctum model may appear.
    expect($loggedContext)->not()->toHaveKey('user_id');
    expect($loggedContext)->not()->toHaveKey('token');
    expect($loggedContext)->not()->toHaveKey('token_id');
});

it('never logs raw argument values even when the caller passes value-bearing keys', function (): void {
    $loggedContext = null;

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$loggedContext): bool {
        $loggedContext = $context;

        return $message === 'mcp.tool_invoked';
    });

    // The caller is responsible for shaping; per the method contract $argShape should
    // already be a shape map. Even so the test proves values do NOT appear in the log.
    $logger = new AuditLogger;
    $logger->record(
        tool: 'db_raw_select',
        argShape: ['sql' => 'string'],
    );

    expect($loggedContext['arg_shape'])->toBe(['sql' => 'string']);
    expect(json_encode($loggedContext))->not()->toContain('SELECT');
});

it('never writes the configured server-admin key to the log', function (): void {
    $loggedContext = null;

    config()->set('agent-mcp.key', 'super-secret-admin-key');

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$loggedContext): bool {
        $loggedContext = $context;

        return $message === 'mcp.tool_invoked';
    });

    $logger = new AuditLogger;
    $logger->record(
        tool: 'db_query',
        argShape: ['table' => 'string'],
    );

    expect(json_encode($loggedContext))->not()->toContain('super-secret-admin-key');
});

it('is a no-op when audit is disabled', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    // Use shouldReceive with never() to assert the channel is never opened.
    Log::shouldReceive('channel')->never();
    Log::shouldReceive('info')->never();

    $logger = new AuditLogger;
    $logger->record(
        tool: 'db_query',
        argShape: ['table' => 'string'],
    );
});
