<?php

namespace Anilcancakir\LaravelAgentMcp\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Marker-scoped, idempotent, atomic injector for the package guideline block.
 *
 * This class mutates user-owned agent instruction files (CLAUDE.md, AGENTS.md,
 * GEMINI.md, ...). Those files hold hand-authored content we must never corrupt,
 * so every write is treated as a data-loss surface:
 *
 *  - Our block lives inside a dedicated marker pair
 *    (<laravel-agent-mcp-guidelines> ... </laravel-agent-mcp-guidelines>) that is
 *    deliberately distinct from laravel-boost's <laravel-boost-guidelines> markers,
 *    so the two regexes can never cross-match and we never touch boost's block.
 *  - When the existing file already carries an unbalanced or duplicated marker set
 *    we refuse to write and throw: a best-effort edit there would risk eating user
 *    content, so we abort loudly and let the user repair the file first.
 *  - The write itself is atomic (write a sibling temp file, then rename over the
 *    target). There is no in-place truncate, so a crash mid-write can never leave a
 *    half-written instruction file.
 *  - A leading UTF-8 BOM and the file's dominant line ending (CRLF vs LF) are
 *    detected up front and re-applied on write-back, so we never strip a BOM or
 *    introduce mixed line endings.
 */
final class GuidelineInjector
{
    /** Opening marker for the package-managed guideline block. */
    private const OPEN_MARKER = '<laravel-agent-mcp-guidelines>';

    /** Closing marker for the package-managed guideline block. */
    private const CLOSE_MARKER = '</laravel-agent-mcp-guidelines>';

    /** Matches exactly one balanced package block (non-greedy across newlines). */
    private const BLOCK_PATTERN = '/<laravel-agent-mcp-guidelines>.*?<\/laravel-agent-mcp-guidelines>/s';

    /**
     * Inject the rendered guideline into each given file inside the managed block.
     *
     * Identical paths are collapsed (the caller already dedupes by canonical path;
     * this is a second line of defence so a duplicated entry can never produce two
     * blocks or two writes). Each file is created when absent, replaced in place
     * when it already holds exactly one balanced block, or appended to otherwise.
     *
     * @param  array<int, string>  $absoluteFilePaths  Absolute target file paths.
     * @param  string  $guideline  The rendered guideline body (wrapped, not raw markers).
     * @return array<int, string> The paths actually written, in first-seen order.
     *
     * @throws RuntimeException When a target already contains an unbalanced or
     *                          duplicated marker set, or when the atomic write fails.
     */
    public function inject(array $absoluteFilePaths, string $guideline): array
    {
        $written = [];

        foreach (array_values(array_unique($absoluteFilePaths)) as $path) {
            $this->injectInto($path, $guideline);

            $written[] = $path;
        }

        return $written;
    }

    /**
     * Inject the guideline into a single file, atomically and marker-scoped.
     *
     * @throws RuntimeException When markers are unbalanced or the write fails.
     */
    private function injectInto(string $path, string $guideline): void
    {
        $exists = File::isFile($path);
        $original = $exists ? File::get($path) : '';

        // 1. Detect the byte-level traits we must preserve on write-back.
        $hasBom = str_starts_with($original, "\xEF\xBB\xBF");
        $usesCrlf = str_contains($original, "\r\n");

        // 2. Work in a normalized LF + no-BOM space so marker logic is encoding-agnostic.
        $content = $hasBom ? substr($original, 3) : $original;
        $content = str_replace("\r\n", "\n", $content);

        // 3. Refuse to touch a file whose markers are not exactly balanced (0/0 or 1/1).
        $this->assertMarkersBalanced($path, $content);

        $block = $this->buildBlock($guideline);

        // 4. Replace the single existing block in place, or append a fresh one.
        if (substr_count($content, self::OPEN_MARKER) === 1) {
            $content = preg_replace(self::BLOCK_PATTERN, $block, $content, 1);
        } elseif ($content === '') {
            $content = $block;
        } else {
            $content = rtrim($content)."\n\n".$block;
        }

        // 5. Collapse 3+ blank lines and guarantee a single trailing newline.
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        $content = rtrim($content)."\n";

        // 6. Re-apply the original EOL and BOM, then write atomically.
        if ($usesCrlf) {
            $content = str_replace("\n", "\r\n", $content);
        }

        if ($hasBom) {
            $content = "\xEF\xBB\xBF".$content;
        }

        $this->writeAtomically($path, $content, $exists);
    }

    /**
     * Assemble the managed block: open, body, a trailing blank line, then close.
     *
     * The body is trimmed so caller-side whitespace can never drift the block, and
     * the open/close markers each sit on their own line for readable diffs.
     */
    private function buildBlock(string $guideline): string
    {
        return self::OPEN_MARKER."\n".trim($guideline)."\n\n".self::CLOSE_MARKER;
    }

    /**
     * Guarantee the file holds either no managed block (0 open / 0 close) or exactly
     * one (1 open / 1 close). Any other shape is ambiguous to edit safely, so we
     * abort with a message that names the file and tells the user how to recover.
     *
     * @throws RuntimeException When the open/close marker counts are unbalanced.
     */
    private function assertMarkersBalanced(string $path, string $content): void
    {
        $open = substr_count($content, self::OPEN_MARKER);
        $close = substr_count($content, self::CLOSE_MARKER);

        if (($open === 0 && $close === 0) || ($open === 1 && $close === 1)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The file %s contains an unbalanced or duplicated %s ... %s marker set '
            .'(%d open, %d close). Fix or remove the markers, then re-run the install.',
            $path,
            self::OPEN_MARKER,
            self::CLOSE_MARKER,
            $open,
            $close,
        ));
    }

    /**
     * Write $content to $path atomically: a sibling temp file in the same directory
     * is written first, then renamed over the target. The temp file MUST share the
     * target's directory so rename() stays a same-filesystem atomic move (a cross
     * device rename would fail). On any failure the temp file is removed and the
     * error is rethrown, never leaving a partial file behind.
     *
     * @param  bool  $exists  Whether the target already existed (to mirror its mode).
     *
     * @throws RuntimeException When the temp write or the rename fails.
     */
    private function writeAtomically(string $path, string $content, bool $exists): void
    {
        $directory = dirname($path);

        File::ensureDirectoryExists($directory);

        $temp = tempnam($directory, 'agent-mcp-guideline-');

        if ($temp === false) {
            throw new RuntimeException(sprintf('Unable to create a temp file in %s for an atomic guideline write.', $directory));
        }

        try {
            if (file_put_contents($temp, $content) === false) {
                throw new RuntimeException(sprintf('Unable to write the temp guideline file at %s.', $temp));
            }

            // Mirror the target's permissions so the rename does not silently downgrade
            // an existing file to tempnam's restrictive 0600 default.
            if ($exists) {
                $mode = fileperms($path);

                if ($mode !== false) {
                    chmod($temp, $mode & 0777);
                }
            }

            if (! @rename($temp, $path)) {
                throw new RuntimeException(sprintf('Unable to move the guideline file into place at %s.', $path));
            }
        } catch (RuntimeException $exception) {
            if (is_file($temp)) {
                @unlink($temp);
            }

            throw $exception;
        }
    }
}
