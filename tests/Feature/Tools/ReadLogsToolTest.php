<?php

use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubReadLogsServer;
use Anilcancakir\LaravelAgentMcp\Tools\ReadLogsTool;
use Laravel\Mcp\Server\McpServiceProvider;

// ReadLogsTool tails the active log file (resolved + containment-checked by
// LogFileResolver), applies an optional level filter, and redacts the output. The
// authoritative deny lives in the base authorize() (tool-enabled flag). Authentication
// is the HTTP layer's job (the server-admin key); the tool no longer checks abilities.

/**
 * The fixed test log file path under the testbench storage/logs directory.
 */
function readLogsTestPath(): string
{
    return storage_path('logs/read-logs-test.log');
}

/**
 * Write the given lines (newline-terminated) to the test log file.
 *
 * @param  array<int, string>  $lines
 */
function writeReadLogsFixture(array $lines): void
{
    $path = readLogsTestPath();

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, implode("\n", $lines)."\n");
}

beforeEach(function (): void {
    // laravel/mcp's provider wires the Request resolution the test pipeline needs.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.read_logs', true);
    config()->set('agent-mcp.logs.max_lines', 200);
    config()->set('agent-mcp.audit.enabled', false);
    config()->set('agent-mcp.redaction.enabled', true);
    config()->set('agent-mcp.redaction.patterns', config('agent-mcp.redaction.patterns'));

    config()->set('logging.channels.agent_test', [
        'driver' => 'single',
        'path' => readLogsTestPath(),
    ]);
    config()->set('agent-mcp.logs.channel', 'agent_test');
});

afterEach(function (): void {
    $path = readLogsTestPath();

    if (is_file($path)) {
        unlink($path);
    }
});

it('returns the last N lines of the resolved log file', function (): void {
    $lines = [];
    for ($i = 1; $i <= 50; $i++) {
        $lines[] = "[2026-05-30 10:00:{$i}] testing.INFO: line {$i}";
    }
    writeReadLogsFixture($lines);

    StubReadLogsServer::tool(ReadLogsTool::class, ['lines' => 5])
        ->assertOk()
        ->assertSee('line 50')
        ->assertSee('line 46')
        ->assertDontSee('line 45');
});

it('clamps the requested line count to the configured maximum', function (): void {
    config()->set('agent-mcp.logs.max_lines', 3);

    $lines = [];
    for ($i = 1; $i <= 20; $i++) {
        $lines[] = "[2026-05-30 10:00:{$i}] testing.INFO: line {$i}";
    }
    writeReadLogsFixture($lines);

    StubReadLogsServer::tool(ReadLogsTool::class, ['lines' => 1000])
        ->assertOk()
        ->assertSee('line 20')
        ->assertSee('line 18')
        ->assertDontSee('line 17');
});

it('filters lines by the requested level', function (): void {
    writeReadLogsFixture([
        '[2026-05-30 10:00:01] testing.INFO: just info',
        '[2026-05-30 10:00:02] testing.ERROR: a real failure',
        '[2026-05-30 10:00:03] testing.DEBUG: noise',
        '[2026-05-30 10:00:04] testing.ERROR: another failure',
    ]);

    StubReadLogsServer::tool(ReadLogsTool::class, ['lines' => 100, 'level' => 'error'])
        ->assertOk()
        ->assertSee('a real failure')
        ->assertSee('another failure')
        ->assertDontSee('just info')
        ->assertDontSee('noise');
});

it('redacts secrets in the returned log lines', function (): void {
    writeReadLogsFixture([
        '[2026-05-30 10:00:01] testing.INFO: user contact secret@example.com logged in',
    ]);

    StubReadLogsServer::tool(ReadLogsTool::class, ['lines' => 10])
        ->assertOk()
        ->assertSee('[REDACTED]')
        ->assertDontSee('secret@example.com');
});

it('denies when the tool is disabled in config', function (): void {
    writeReadLogsFixture(['[2026-05-30 10:00:01] testing.INFO: line']);

    config()->set('agent-mcp.tools.read_logs', false);

    StubReadLogsServer::tool(ReadLogsTool::class, ['lines' => 10])
        ->assertHasErrors();
});

it('returns a clean error when the configured log path escapes storage/logs', function (): void {
    config()->set('logging.channels.agent_test', [
        'driver' => 'single',
        'path' => storage_path('logs/../../../../../../etc/passwd'),
    ]);

    StubReadLogsServer::tool(ReadLogsTool::class, ['lines' => 10])
        ->assertHasErrors()
        ->assertDontSee('root:');
});

it('handles a missing log file cleanly without leaking a path', function (): void {
    $path = readLogsTestPath();

    if (is_file($path)) {
        unlink($path);
    }

    StubReadLogsServer::tool(ReadLogsTool::class, ['lines' => 10])
        ->assertOk();
});
