<?php

use Illuminate\Support\Facades\Artisan;

// The three CLI commands must be registered by the package service provider so they are
// discoverable as artisan commands, and agent-mcp:tools must run end-to-end.

it('registers the agent-mcp CLI commands', function (): void {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('agent-mcp:call');
    expect($commands)->toContain('agent-mcp:tools');
    expect($commands)->toContain('agent-mcp:schema');
});

it('runs agent-mcp:tools end-to-end with a success exit code', function (): void {
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.tools.db_schema', true);

    // The command writes the JSON payload to its own stdout stream (raw + pipeable, like
    // StdioBridgeCommand), so Artisan::output() does not capture it; the payload content is
    // asserted in ListToolsCommandTest via the injected stream. Here the contract is that the
    // registered command runs end-to-end and exits 0.
    expect(Artisan::call('agent-mcp:tools'))->toBe(0);
});
