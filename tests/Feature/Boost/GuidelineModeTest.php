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
 * Also asserts core.md exists as a static superset fallback containing both
 * the MCP investigation content and the CLI content.
 */
describe('core guideline mode rendering', function (): void {

    // Absolute paths to the guideline blade + its static .md fallback.
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
    // core.md superset fallback
    // -------------------------------------------------------------------------

    it('core.md exists as a static fallback', function () use ($mdPath): void {
        expect(File::exists($mdPath))->toBeTrue();
    });

    it('core.md contains the MCP investigation marker', function () use ($mdPath): void {
        expect(File::get($mdPath))->toContain('db_schema');
    });

    it('core.md contains the CLI usage marker', function () use ($mdPath): void {
        expect(File::get($mdPath))->toContain('agent-mcp:call');
    });
});
