<?php

use Anilcancakir\LaravelAgentMcp\Support\InstallMode;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

// agent-mcp:install is a two-mode setup command. It records the chosen mode in a
// committed .agent-mcp.json, publishes the config + agent assets, and prints
// mode-tailored guidance:
//   shared in BOTH modes: AGENT_MCP_KEY setup, readonly DB user setup, app.debug warning,
//                         and the boost next-step instruction;
//   mcp mode adds: the HTTP + stdio .mcp.json blocks and the claude mcp add one-liner;
//   cli mode adds: an agent-mcp:call / agent-mcp:tools usage block (local + remote)
//                  and SKIPS the .mcp.json blocks / claude mcp add.
// The command MUST NOT print a real key, reference Sanctum, or touch .gitignore.

beforeEach(function (): void {
    File::delete(InstallMode::path());
});

afterEach(function (): void {
    File::delete(InstallMode::path());
});

// -----------------------------------------------------------------------------
// Mode resolution + recording
// -----------------------------------------------------------------------------

it('defaults to mcp on a bare run and records it', function (): void {
    // A bare run prompts interactively (default mcp); selecting the default records mcp.
    artisan('agent-mcp:install')
        ->expectsChoice('Install mode', 'mcp', ['mcp', 'cli'])
        ->assertOk();

    expect(InstallMode::current())->toBe('mcp');
});

it('records cli mode when --mode=cli is passed', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli'])->assertOk();

    expect(InstallMode::current())->toBe('cli');
});

it('records mcp mode when --mode=mcp is passed', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp'])->assertOk();

    expect(InstallMode::current())->toBe('mcp');
});

it('exits non-zero for an invalid --mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'bogus'])->assertFailed();
});

it('does not write .agent-mcp.json for an invalid --mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'bogus']);

    expect(File::exists(InstallMode::path()))->toBeFalse();
});

it('overwrites a previously recorded mode when re-run with a different mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp'])->assertOk();
    expect(InstallMode::current())->toBe('mcp');

    artisan('agent-mcp:install', ['--mode' => 'cli'])->assertOk();
    expect(InstallMode::current())->toBe('cli');
});

// -----------------------------------------------------------------------------
// Shared guidance (BOTH modes)
// -----------------------------------------------------------------------------

it('prints the AGENT_MCP_KEY generation hint in both modes', function (string $mode): void {
    artisan('agent-mcp:install', ['--mode' => $mode])
        ->expectsOutputToContain('AGENT_MCP_KEY')
        ->expectsOutputToContain('bin2hex(random_bytes(32))')
        ->assertOk();
})->with(['mcp', 'cli']);

it('prints per-engine readonly DB user reminders in both modes', function (string $mode): void {
    artisan('agent-mcp:install', ['--mode' => $mode])
        ->expectsOutputToContain('MySQL')
        ->expectsOutputToContain('PostgreSQL')
        ->expectsOutputToContain('SQLite')
        ->assertOk();
})->with(['mcp', 'cli']);

it('prints the app.debug warning in both modes', function (string $mode): void {
    artisan('agent-mcp:install', ['--mode' => $mode])
        ->expectsOutputToContain('app.debug')
        ->assertOk();
})->with(['mcp', 'cli']);

it('prints the boost next-step instruction in both modes', function (string $mode): void {
    artisan('agent-mcp:install', ['--mode' => $mode])
        ->expectsOutputToContain('boost:install')
        ->assertOk();
})->with(['mcp', 'cli']);

// -----------------------------------------------------------------------------
// MCP mode (default) guidance
// -----------------------------------------------------------------------------

it('prints the HTTP client block with Bearer auth in mcp mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp'])
        ->expectsOutputToContain('"type": "http"')
        ->expectsOutputToContain('Bearer')
        ->assertOk();
});

it('prints the stdio bridge block with AGENT_MCP_URL env in mcp mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp'])
        ->expectsOutputToContain('agent-mcp:stdio')
        ->expectsOutputToContain('AGENT_MCP_URL')
        ->assertOk();
});

it('prints the claude mcp add one-liner in mcp mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp'])
        ->expectsOutputToContain('claude mcp add')
        ->assertOk();
});

it('prints the app URL in the HTTP block in mcp mode', function (): void {
    config()->set('app.url', 'https://example.test');
    config()->set('agent-mcp.route', 'agent-mcp');

    artisan('agent-mcp:install', ['--mode' => 'mcp'])
        ->expectsOutputToContain('https://example.test/agent-mcp')
        ->assertOk();
});

it('defaults to the mcp .mcp.json blocks on a bare run', function (): void {
    artisan('agent-mcp:install')
        ->expectsChoice('Install mode', 'mcp', ['mcp', 'cli'])
        ->expectsOutputToContain('"type": "http"')
        ->expectsOutputToContain('claude mcp add')
        ->assertOk();
});

// -----------------------------------------------------------------------------
// CLI mode guidance
// -----------------------------------------------------------------------------

it('prints the agent-mcp:call usage block in cli mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli'])
        ->expectsOutputToContain('agent-mcp:call')
        ->expectsOutputToContain('agent-mcp:tools')
        ->expectsOutputToContain('--allow-tty')
        ->assertOk();
});

it('notes remote mode via AGENT_MCP_URL in cli mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli'])
        ->expectsOutputToContain('AGENT_MCP_URL')
        ->assertOk();
});

it('does not print the .mcp.json HTTP block in cli mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli'])
        ->doesntExpectOutputToContain('"type": "http"')
        ->assertOk();
});

it('does not print the claude mcp add one-liner in cli mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli'])
        ->doesntExpectOutputToContain('claude mcp add')
        ->assertOk();
});

// -----------------------------------------------------------------------------
// Source hygiene
// -----------------------------------------------------------------------------

it('does not reference Sanctum or createToken', function (): void {
    artisan('agent-mcp:install')
        ->expectsChoice('Install mode', 'mcp', ['mcp', 'cli'])
        ->assertOk();

    // Confirm legacy Sanctum terms are absent by grepping the command source: the
    // command must carry no createToken/Sanctum/abilities language under key auth.
    // Separate expectations (not a chain) keep each subject typed as a string.
    $source = File::get(__DIR__.'/../../../src/Commands/InstallCommand.php');

    expect($source)->not->toContain('createToken');
    expect($source)->not->toContain('sanctum');
    expect($source)->not->toContain('Sanctum');
    expect($source)->not->toContain('abilities');
});
