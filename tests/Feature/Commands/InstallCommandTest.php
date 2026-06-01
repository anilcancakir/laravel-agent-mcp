<?php

use Anilcancakir\LaravelAgentMcp\Support\InstallMode;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

/**
 * Remove every agent file/dir the injection tests can create so each test runs
 * against a clean base_path() and never pollutes the package repo root.
 */
function cleanAgentArtifacts(): void
{
    foreach (['CLAUDE.md', 'AGENTS.md', 'GEMINI.md'] as $file) {
        File::delete(base_path($file));
    }

    foreach (['.claude', '.agents', '.cursor', '.github', '.junie', '.kiro'] as $dir) {
        File::deleteDirectory(base_path($dir));
    }
}

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
    cleanAgentArtifacts();
});

afterEach(function (): void {
    File::delete(InstallMode::path());
    cleanAgentArtifacts();
});

// -----------------------------------------------------------------------------
// Mode resolution + recording
// -----------------------------------------------------------------------------

it('defaults to mcp on a bare run and records it', function (): void {
    // A bare run prompts interactively (default mcp); selecting the default records mcp.
    // --no-inject keeps the run off the agent multiselect so the mode prompt is the
    // only interaction this test drives.
    artisan('agent-mcp:install', ['--no-inject' => true])
        ->expectsChoice('Install mode', 'mcp', ['mcp', 'cli'])
        ->assertOk();

    expect(InstallMode::current())->toBe('mcp');
});

it('records cli mode when --mode=cli is passed', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli', '--agents' => 'claude_code'])->assertOk();

    expect(InstallMode::current())->toBe('cli');
});

it('records mcp mode when --mode=mcp is passed', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'claude_code'])->assertOk();

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
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'claude_code'])->assertOk();
    expect(InstallMode::current())->toBe('mcp');

    artisan('agent-mcp:install', ['--mode' => 'cli', '--agents' => 'claude_code'])->assertOk();
    expect(InstallMode::current())->toBe('cli');
});

// -----------------------------------------------------------------------------
// Shared guidance (BOTH modes)
// -----------------------------------------------------------------------------

it('prints the AGENT_MCP_KEY generation hint in both modes', function (string $mode): void {
    artisan('agent-mcp:install', ['--mode' => $mode, '--agents' => 'claude_code'])
        ->expectsOutputToContain('AGENT_MCP_KEY')
        ->expectsOutputToContain('bin2hex(random_bytes(32))')
        ->assertOk();
})->with(['mcp', 'cli']);

it('prints per-engine readonly DB user reminders in both modes', function (string $mode): void {
    artisan('agent-mcp:install', ['--mode' => $mode, '--agents' => 'claude_code'])
        ->expectsOutputToContain('MySQL')
        ->expectsOutputToContain('PostgreSQL')
        ->expectsOutputToContain('SQLite')
        ->assertOk();
})->with(['mcp', 'cli']);

it('prints the app.debug warning in both modes', function (string $mode): void {
    artisan('agent-mcp:install', ['--mode' => $mode, '--agents' => 'claude_code'])
        ->expectsOutputToContain('app.debug')
        ->assertOk();
})->with(['mcp', 'cli']);

it('prints the boost next-step instruction when injection is skipped', function (string $mode): void {
    // With laravel-boost absent injection is the default, so the boost next-step
    // only prints when injection is explicitly skipped (--no-inject).
    artisan('agent-mcp:install', ['--mode' => $mode, '--no-inject' => true])
        ->expectsOutputToContain('boost:install')
        ->assertOk();
})->with(['mcp', 'cli']);

// -----------------------------------------------------------------------------
// MCP mode (default) guidance
// -----------------------------------------------------------------------------

it('prints the HTTP client block with Bearer auth in mcp mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'claude_code'])
        ->expectsOutputToContain('"type": "http"')
        ->expectsOutputToContain('Bearer')
        ->assertOk();
});

it('prints the stdio bridge block with AGENT_MCP_URL env in mcp mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'claude_code'])
        ->expectsOutputToContain('agent-mcp:stdio')
        ->expectsOutputToContain('AGENT_MCP_URL')
        ->assertOk();
});

it('prints the claude mcp add one-liner in mcp mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'claude_code'])
        ->expectsOutputToContain('claude mcp add')
        ->assertOk();
});

it('prints the app URL in the HTTP block in mcp mode', function (): void {
    config()->set('app.url', 'https://example.test');
    config()->set('agent-mcp.route', 'agent-mcp');

    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'claude_code'])
        ->expectsOutputToContain('https://example.test/agent-mcp')
        ->assertOk();
});

it('defaults to the mcp .mcp.json blocks on a bare run', function (): void {
    artisan('agent-mcp:install', ['--no-inject' => true])
        ->expectsChoice('Install mode', 'mcp', ['mcp', 'cli'])
        ->expectsOutputToContain('"type": "http"')
        ->expectsOutputToContain('claude mcp add')
        ->assertOk();
});

// -----------------------------------------------------------------------------
// CLI mode guidance
// -----------------------------------------------------------------------------

it('prints the agent-mcp:call usage block in cli mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli', '--agents' => 'claude_code'])
        ->expectsOutputToContain('agent-mcp:call')
        ->expectsOutputToContain('agent-mcp:tools')
        ->expectsOutputToContain('--allow-tty')
        ->assertOk();
});

it('notes remote mode via AGENT_MCP_URL in cli mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli', '--agents' => 'claude_code'])
        ->expectsOutputToContain('AGENT_MCP_URL')
        ->assertOk();
});

it('does not print the .mcp.json HTTP block in cli mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli', '--agents' => 'claude_code'])
        ->doesntExpectOutputToContain('"type": "http"')
        ->assertOk();
});

it('does not print the claude mcp add one-liner in cli mode', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli', '--agents' => 'claude_code'])
        ->doesntExpectOutputToContain('claude mcp add')
        ->assertOk();
});

// -----------------------------------------------------------------------------
// URL option (--url) and commit-url preservation
// -----------------------------------------------------------------------------

it('persists a valid https url in .agent-mcp.json when --url is given', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli', '--url' => 'https://x.test', '--agents' => 'claude_code'])
        ->assertOk();

    expect(InstallMode::url())->toBe('https://x.test');
});

it('returns FAILURE and writes no url when --url carries an invalid scheme', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'cli', '--url' => 'http://evil.com'])
        ->assertFailed();

    // The file must not exist at all (written after mode resolution, which we never reach).
    expect(File::exists(InstallMode::path()))->toBeFalse();
});

it('preserves an existing committed url when re-running without --url in non-interactive mode', function (): void {
    // Seed a prior install with a committed url.
    artisan('agent-mcp:install', ['--mode' => 'cli', '--url' => 'https://x.test', '--agents' => 'claude_code'])
        ->assertOk();

    expect(InstallMode::url())->toBe('https://x.test');

    // Re-run without --url; non-interactive (no expectsQuestion) must preserve the url.
    artisan('agent-mcp:install', ['--mode' => 'cli', '--agents' => 'claude_code'])
        ->assertOk();

    expect(InstallMode::url())->toBe('https://x.test');
});

it('prompts for the remote url in a bare interactive cli install and records it', function (): void {
    // A bare interactive install: mode choice selects cli, then the url prompt fires.
    artisan('agent-mcp:install', ['--no-inject' => true])
        ->expectsChoice('Install mode', 'cli', ['mcp', 'cli'])
        ->expectsQuestion('Remote endpoint URL (leave blank for none)', 'https://prompt.test')
        ->assertOk();

    expect(InstallMode::url())->toBe('https://prompt.test');
});

it('accepts a blank url prompt and records no url in a bare interactive cli install', function (): void {
    artisan('agent-mcp:install', ['--no-inject' => true])
        ->expectsChoice('Install mode', 'cli', ['mcp', 'cli'])
        ->expectsQuestion('Remote endpoint URL (leave blank for none)', '')
        ->assertOk();

    expect(InstallMode::url())->toBeNull();
});

it('prompts for url and fails when an invalid url is entered in a bare interactive cli install', function (): void {
    artisan('agent-mcp:install', ['--no-inject' => true])
        ->expectsChoice('Install mode', 'cli', ['mcp', 'cli'])
        ->expectsQuestion('Remote endpoint URL (leave blank for none)', 'http://evil.com')
        ->assertFailed();
});

// -----------------------------------------------------------------------------
// Source hygiene
// -----------------------------------------------------------------------------

it('does not reference Sanctum or createToken', function (): void {
    artisan('agent-mcp:install', ['--no-inject' => true])
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

// -----------------------------------------------------------------------------
// Boost-independent injection (boost absent in the test env)
// -----------------------------------------------------------------------------

it('injects the mcp guideline + skill into claude_code', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'claude_code'])
        ->assertOk();

    // 1. The guideline lands inside our marker block with the mcp-only marker.
    $guideline = File::get(base_path('CLAUDE.md'));
    expect($guideline)
        ->toContain('<laravel-agent-mcp-guidelines>')
        ->toContain('</laravel-agent-mcp-guidelines>')
        ->toContain('db_raw_select');

    // 2. The active-mode skill is copied without the raw blade render source.
    expect(File::exists(base_path('.claude/skills/agent-mcp-investigation/SKILL.md')))->toBeTrue();
    expect(File::exists(base_path('.claude/skills/agent-mcp-investigation/SKILL.blade.php')))->toBeFalse();
});

it('injects the cli guideline + skill and removes the prior mode dir', function (): void {
    // Seed a prior mcp install so the mode switch has a dir to self-heal.
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'claude_code'])->assertOk();
    expect(File::exists(base_path('.claude/skills/agent-mcp-investigation/SKILL.md')))->toBeTrue();

    artisan('agent-mcp:install', ['--mode' => 'cli', '--agents' => 'claude_code'])->assertOk();

    $guideline = File::get(base_path('CLAUDE.md'));
    expect($guideline)->toContain('agent-mcp:call');

    expect(File::exists(base_path('.claude/skills/agent-mcp-cli/SKILL.md')))->toBeTrue();
    expect(File::isDirectory(base_path('.claude/skills/agent-mcp-investigation')))->toBeFalse();
});

it('skips all agent writes with --no-inject and still succeeds', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'claude_code', '--no-inject' => true])
        ->expectsOutputToContain('Skipped')
        ->assertOk();

    expect(File::exists(base_path('CLAUDE.md')))->toBeFalse();
    expect(File::isDirectory(base_path('.claude/skills')))->toBeFalse();
});

it('dedupes the AGENTS.md guideline file across targets', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'cursor,codex'])
        ->assertOk();

    $guideline = File::get(base_path('AGENTS.md'));

    expect(substr_count($guideline, '<laravel-agent-mcp-guidelines>'))->toBe(1);
    expect(substr_count($guideline, '</laravel-agent-mcp-guidelines>'))->toBe(1);
});

it('fails with a clear message for an invalid --agents key and writes nothing', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'nope'])
        ->expectsOutputToContain('nope')
        ->assertFailed();

    expect(File::exists(base_path('CLAUDE.md')))->toBeFalse();
    expect(File::exists(base_path('AGENTS.md')))->toBeFalse();
});

it('re-runs idempotently leaving a single guideline block', function (): void {
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'claude_code'])->assertOk();
    artisan('agent-mcp:install', ['--mode' => 'mcp', '--agents' => 'claude_code'])->assertOk();

    $guideline = File::get(base_path('CLAUDE.md'));

    expect(substr_count($guideline, '<laravel-agent-mcp-guidelines>'))->toBe(1);
    expect(substr_count($guideline, '</laravel-agent-mcp-guidelines>'))->toBe(1);
});
