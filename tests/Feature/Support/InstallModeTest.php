<?php

use Anilcancakir\LaravelAgentMcp\Support\InstallMode;
use Illuminate\Support\Facades\File;

describe('InstallMode', function (): void {

    beforeEach(function (): void {
        File::delete(InstallMode::path());
    });

    afterEach(function (): void {
        File::delete(InstallMode::path());
    });

    // -------------------------------------------------------------------------
    // path() / modes()
    // -------------------------------------------------------------------------

    it('points at .agent-mcp.json in the project root', function (): void {
        expect(InstallMode::path())->toBe(base_path('.agent-mcp.json'));
    });

    it('exposes the two supported modes', function (): void {
        expect(InstallMode::modes())->toBe(['mcp', 'cli']);
    });

    // -------------------------------------------------------------------------
    // current(): default fallback
    // -------------------------------------------------------------------------

    it('falls back to mcp when the file is absent', function (): void {
        expect(InstallMode::current())->toBe('mcp');
    });

    it('falls back to mcp when the JSON is malformed', function (): void {
        File::put(InstallMode::path(), '{not valid json');

        expect(InstallMode::current())->toBe('mcp');
    });

    it('falls back to mcp when the mode value is not a known mode', function (): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'bogus', 'version' => 1]));

        expect(InstallMode::current())->toBe('mcp');
    });

    it('falls back to mcp when the mode key is missing', function (): void {
        File::put(InstallMode::path(), json_encode(['version' => 1]));

        expect(InstallMode::current())->toBe('mcp');
    });

    it('falls back to mcp when the mode value is not a string', function (): void {
        File::put(InstallMode::path(), json_encode(['mode' => 1, 'version' => 1]));

        expect(InstallMode::current())->toBe('mcp');
    });

    it('falls back to mcp when the decoded payload is not an object', function (): void {
        File::put(InstallMode::path(), json_encode(['mcp']));

        expect(InstallMode::current())->toBe('mcp');
    });

    // -------------------------------------------------------------------------
    // current(): valid reads
    // -------------------------------------------------------------------------

    it('reads the recorded mcp mode', function (): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'mcp', 'version' => 1]));

        expect(InstallMode::current())->toBe('mcp');
    });

    it('reads the recorded cli mode', function (): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'cli', 'version' => 1]));

        expect(InstallMode::current())->toBe('cli');
    });

    it('tolerates an unknown version and uses the recorded mode', function (): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'cli', 'version' => 999]));

        expect(InstallMode::current())->toBe('cli');
    });

    it('tolerates a missing version and uses the recorded mode', function (): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'cli']));

        expect(InstallMode::current())->toBe('cli');
    });

    // -------------------------------------------------------------------------
    // write()
    // -------------------------------------------------------------------------

    it('round-trips a written mode through current()', function (): void {
        InstallMode::write('cli');

        expect(InstallMode::current())->toBe('cli');
    });

    it('writes pretty JSON with mode and version', function (): void {
        InstallMode::write('cli');

        $contents = File::get(InstallMode::path());

        expect($contents)->toBe(<<<'JSON'
            {
                "mode": "cli",
                "version": 1
            }
            JSON);
    });

    it('throws InvalidArgumentException for an unknown mode', function (): void {
        $thrown = null;

        try {
            InstallMode::write('bogus');
        } catch (InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeInstanceOf(InvalidArgumentException::class);
    });

    it('does not write the file when the mode is invalid', function (): void {
        try {
            InstallMode::write('bogus');
        } catch (InvalidArgumentException) {
            // Expected; assert below that nothing was written.
        }

        expect(File::exists(InstallMode::path()))->toBeFalse();
    });
});
