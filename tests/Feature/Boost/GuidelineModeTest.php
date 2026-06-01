<?php

use Anilcancakir\LaravelAgentMcp\Support\InstallMode;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

/**
 * GuidelineModeTest
 *
 * Asserts that core.blade.php renders mode-appropriate content: cli branch
 * contains agent-mcp:call but not the MCP investigation workflow, mcp branch
 * contains the db_schema investigation marker but not agent-mcp:call, and the
 * shared preamble (read-only framing + server-key mention) appears in both.
 *
 * Also asserts no sibling core.md is shipped: boost's third-party guideline
 * discovery enumerates both core.blade.php and core.md and lets the .md win a
 * last-write-wins put keyed by package name, which would override the
 * mode-branched blade with a static superset. The blade alone keeps boost
 * rendering per mode.
 */
describe('core guideline mode rendering', function (): void {

    // Absolute path to the guideline blade. The sibling core.md must NOT exist
    // (see the regression guard at the end of this file).
    $bladePath = __DIR__.'/../../../resources/boost/guidelines/core.blade.php';
    $mdPath = __DIR__.'/../../../resources/boost/guidelines/core.md';

    beforeEach(function (): void {
        File::delete(InstallMode::path());
    });

    afterEach(function (): void {
        File::delete(InstallMode::path());
    });

    // -------------------------------------------------------------------------
    // Shared preamble: must appear in BOTH modes
    // -------------------------------------------------------------------------

    it('renders the read-only server-key preamble in mcp mode', function () use ($bladePath): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'mcp', 'version' => 1]));

        $output = Blade::render(file_get_contents($bladePath));

        expect($output)
            ->toContain('AGENT_MCP_KEY')
            ->toContain('read-only');
    });

    it('renders the read-only server-key preamble in cli mode', function () use ($bladePath): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'cli', 'version' => 1]));

        $output = Blade::render(file_get_contents($bladePath));

        expect($output)
            ->toContain('AGENT_MCP_KEY')
            ->toContain('read-only');
    });

    // -------------------------------------------------------------------------
    // MCP mode: investigation marker present, CLI marker absent
    // -------------------------------------------------------------------------

    it('renders the MCP investigation tool list in mcp mode', function () use ($bladePath): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'mcp', 'version' => 1]));

        $output = Blade::render(file_get_contents($bladePath));

        // db_raw_select is listed as a discrete tool only in the MCP branch
        expect($output)->toContain('db_raw_select');
    });

    it('does not render agent-mcp:call in mcp mode', function () use ($bladePath): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'mcp', 'version' => 1]));

        $output = Blade::render(file_get_contents($bladePath));

        expect($output)->not->toContain('agent-mcp:call');
    });

    // -------------------------------------------------------------------------
    // CLI mode: CLI marker present, MCP investigation marker absent
    // -------------------------------------------------------------------------

    it('renders agent-mcp:call in cli mode', function () use ($bladePath): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'cli', 'version' => 1]));

        $output = Blade::render(file_get_contents($bladePath));

        expect($output)->toContain('agent-mcp:call');
    });

    it('does not render the MCP-only tool list in cli mode', function () use ($bladePath): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'cli', 'version' => 1]));

        $output = Blade::render(file_get_contents($bladePath));

        // db_raw_select is a discrete MCP tool entry; absent from the CLI branch
        expect($output)->not->toContain('db_raw_select');
    });

    // -------------------------------------------------------------------------
    // Absent file: falls back to mcp mode
    // -------------------------------------------------------------------------

    it('defaults to mcp mode when .agent-mcp.json is absent', function () use ($bladePath): void {
        // No file written; InstallMode::current() returns 'mcp'
        $output = Blade::render(file_get_contents($bladePath));

        expect($output)->toContain('db_raw_select');
        expect($output)->not->toContain('agent-mcp:call');
    });

    // -------------------------------------------------------------------------
    // No sibling core.md: boost's third-party guideline discovery enumerates
    // every file in the dir and a last-write-wins put keyed by package name lets
    // a .md override the mode-branched blade with a static superset. Shipping the
    // blade alone keeps the guideline mode-tailored under boost.
    // -------------------------------------------------------------------------

    it('ships no sibling core.md so boost renders the mode-branched blade', function () use ($mdPath): void {
        expect(File::exists($mdPath))->toBeFalse();
    });
});
