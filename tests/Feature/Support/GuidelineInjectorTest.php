<?php

use Anilcancakir\LaravelAgentMcp\Support\GuidelineInjector;
use Illuminate\Support\Facades\File;
use RuntimeException;

// GuidelineInjector mutates user-owned instruction files (CLAUDE.md, AGENTS.md, ...).
// It is a data-loss surface: it must never corrupt content outside its own marker
// block, must abort (no write) on unbalanced markers, and must preserve a file's
// byte-level encoding traits (leading UTF-8 BOM, dominant CRLF/LF line endings).
// All fixtures live under base_path() and are removed in before/afterEach.

describe('GuidelineInjector', function (): void {

    $cleanup = function (): void {
        File::deleteDirectory(base_path('injector-fixtures'));
    };

    beforeEach(function () use ($cleanup): void {
        $cleanup();
        File::ensureDirectoryExists(base_path('injector-fixtures'));
    });

    afterEach(function () use ($cleanup): void {
        $cleanup();
    });

    $fixture = fn (string $name): string => base_path('injector-fixtures/'.$name);

    // -------------------------------------------------------------------------
    // Fresh file
    // -------------------------------------------------------------------------

    it('creates a fresh file with exactly one marker block', function () use ($fixture): void {
        $path = $fixture('fresh.md');

        $written = (new GuidelineInjector)->inject([$path], 'Use the read-only tools.');

        expect($written)->toBe([$path]);
        expect(File::exists($path))->toBeTrue();

        $content = File::get($path);

        expect(substr_count($content, '<laravel-agent-mcp-guidelines>'))->toBe(1);
        expect(substr_count($content, '</laravel-agent-mcp-guidelines>'))->toBe(1);
        expect($content)->toContain('Use the read-only tools.');
        expect($content)->toEndWith("\n");
    });

    it('creates parent directories that do not yet exist', function () use ($fixture): void {
        $path = $fixture('nested/deep/CLAUDE.md');

        (new GuidelineInjector)->inject([$path], 'Nested guideline.');

        expect(File::exists($path))->toBeTrue();
        expect(File::get($path))->toContain('Nested guideline.');
    });

    // -------------------------------------------------------------------------
    // Re-injection (idempotent in-place replace)
    // -------------------------------------------------------------------------

    it('replaces in place on re-injection, keeping a single block and surrounding text', function () use ($fixture): void {
        $path = $fixture('rerun.md');
        File::put($path, "# My Notes\n\nKeep this paragraph.\n");

        $injector = new GuidelineInjector;
        $injector->inject([$path], 'First version.');
        $injector->inject([$path], 'Second version.');

        $content = File::get($path);

        expect(substr_count($content, '<laravel-agent-mcp-guidelines>'))->toBe(1);
        expect(substr_count($content, '</laravel-agent-mcp-guidelines>'))->toBe(1);
        expect($content)->toContain('Second version.');
        expect($content)->not->toContain('First version.');
        expect($content)->toContain('# My Notes');
        expect($content)->toContain('Keep this paragraph.');
    });

    // -------------------------------------------------------------------------
    // Boost block must stay byte-identical
    // -------------------------------------------------------------------------

    it('leaves a laravel-boost-guidelines block byte-identical outside our block', function () use ($fixture): void {
        $path = $fixture('boost.md');
        $boostBlock = "<laravel-boost-guidelines>\nBoost owns this. Do not touch.\n</laravel-boost-guidelines>";
        File::put($path, "# Header\n\n".$boostBlock."\n");

        (new GuidelineInjector)->inject([$path], 'Agent MCP guideline.');

        $content = File::get($path);

        expect($content)->toContain($boostBlock);
        expect(substr_count($content, '<laravel-boost-guidelines>'))->toBe(1);
        expect(substr_count($content, '</laravel-boost-guidelines>'))->toBe(1);
        expect(substr_count($content, '<laravel-agent-mcp-guidelines>'))->toBe(1);
    });

    // -------------------------------------------------------------------------
    // Unbalanced markers abort without modifying the file
    // -------------------------------------------------------------------------

    it('throws and leaves the file unchanged when the open marker is duplicated', function () use ($fixture): void {
        $path = $fixture('duplicated.md');
        File::put(
            $path,
            "<laravel-agent-mcp-guidelines>\nold one\n</laravel-agent-mcp-guidelines>\n\n".
            "<laravel-agent-mcp-guidelines>\nold two\n</laravel-agent-mcp-guidelines>\n",
        );

        $before = md5_file($path);

        expect(fn () => (new GuidelineInjector)->inject([$path], 'New.'))
            ->toThrow(RuntimeException::class);

        expect(md5_file($path))->toBe($before);
    });

    it('throws and leaves the file unchanged when a marker is unbalanced', function () use ($fixture): void {
        $path = $fixture('unbalanced.md');
        File::put($path, "intro\n\n<laravel-agent-mcp-guidelines>\nno close marker here\n");

        $before = md5_file($path);

        expect(fn () => (new GuidelineInjector)->inject([$path], 'New.'))
            ->toThrow(RuntimeException::class);

        expect(md5_file($path))->toBe($before);
    });

    it('names the offending file in the thrown message', function () use ($fixture): void {
        $path = $fixture('named.md');
        File::put($path, "<laravel-agent-mcp-guidelines>\nopen only\n");

        expect(fn () => (new GuidelineInjector)->inject([$path], 'New.'))
            ->toThrow(RuntimeException::class, $path);
    });

    // -------------------------------------------------------------------------
    // EOL + BOM preservation
    // -------------------------------------------------------------------------

    it('keeps a CRLF file CRLF with no mixed line endings', function () use ($fixture): void {
        $path = $fixture('crlf.md');
        File::put($path, "# Title\r\n\r\nBody line.\r\n");

        (new GuidelineInjector)->inject([$path], 'CRLF guideline.');

        $content = File::get($path);

        // Every LF must be part of a CRLF pair: no bare LF survives.
        expect(preg_match('/(?<!\r)\n/', $content))->toBe(0);
        expect($content)->toContain("\r\n");
        expect($content)->toContain('CRLF guideline.');
        expect($content)->toContain('Body line.');
    });

    it('keeps a leading UTF-8 BOM intact', function () use ($fixture): void {
        $path = $fixture('bom.md');
        $bom = "\xEF\xBB\xBF";
        File::put($path, $bom."# BOM file\n\ncontent\n");

        (new GuidelineInjector)->inject([$path], 'BOM guideline.');

        $content = File::get($path);

        expect(str_starts_with($content, $bom))->toBeTrue();
        // The BOM must appear exactly once (not duplicated by the write-back).
        expect(substr_count($content, $bom))->toBe(1);
        expect($content)->toContain('BOM guideline.');
    });

    // -------------------------------------------------------------------------
    // Append keeps existing content; trailing-newline / blank-line normalization
    // -------------------------------------------------------------------------

    it('appends to a non-empty file preserving existing content', function () use ($fixture): void {
        $path = $fixture('append.md');
        File::put($path, "# Existing\n\nSome prose.\n");

        (new GuidelineInjector)->inject([$path], 'Appended guideline.');

        $content = File::get($path);

        expect($content)->toStartWith("# Existing\n\nSome prose.");
        expect($content)->toContain('Appended guideline.');
        // No run of 3+ newlines anywhere.
        expect(preg_match('/\n{3,}/', $content))->toBe(0);
        expect($content)->toEndWith("\n");
        expect(substr_count($content, "\n\n"))->toBeGreaterThan(0);
    });

    // -------------------------------------------------------------------------
    // Dedupe + multi-path return contract
    // -------------------------------------------------------------------------

    it('collapses identical paths and writes them once', function () use ($fixture): void {
        $path = $fixture('dupe-path.md');

        $written = (new GuidelineInjector)->inject([$path, $path], 'Once.');

        expect($written)->toBe([$path]);
        expect(substr_count(File::get($path), '<laravel-agent-mcp-guidelines>'))->toBe(1);
    });

    it('returns every distinct written path', function () use ($fixture): void {
        $a = $fixture('multi-a.md');
        $b = $fixture('multi-b.md');

        $written = (new GuidelineInjector)->inject([$a, $b], 'Multi.');

        expect($written)->toEqualCanonicalizing([$a, $b]);
        expect(File::get($a))->toContain('Multi.');
        expect(File::get($b))->toContain('Multi.');
    });

    it('trims surrounding whitespace from the guideline before wrapping it', function () use ($fixture): void {
        $path = $fixture('trim.md');

        (new GuidelineInjector)->inject([$path], "\n\n   Trimmed body.   \n\n");

        $content = File::get($path);

        expect($content)->toContain("<laravel-agent-mcp-guidelines>\nTrimmed body.\n\n</laravel-agent-mcp-guidelines>");
    });
});
