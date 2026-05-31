<?php

namespace Anilcancakir\LaravelAgentMcp\Support;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Installs the active-mode skill into one or more agent skills-root directories.
 *
 * This is a delete-before-copy surface that writes into user-owned directories,
 * so the deletion is deliberately narrow: only the two package-managed namespaced
 * basenames (agent-mcp-investigation / agent-mcp-cli) are ever removed, by EXACT
 * path, never by glob. A mode switch self-heals by removing the other mode's dir
 * so a project only ever carries the active mode's skill.
 *
 * Only the publishable files are copied (SKILL.md + references/). The source dirs
 * also ship a SKILL.blade.php (boost's mode-render source); a recursive copy would
 * leak that raw @if(... InstallMode::current() ...) template into the user's skill
 * dir, so the whole source dir is never copied.
 */
final class SkillInstaller
{
    /** Managed skill-dir basename for each mode (the only names this class ever deletes). */
    private const BASENAMES = [
        'mcp' => 'agent-mcp-investigation',
        'cli' => 'agent-mcp-cli',
    ];

    /**
     * Install the active-mode skill into each unique skills-root directory.
     *
     * For every distinct root: ensure it exists, remove the other mode's managed
     * dir (mode-switch self-heal), then replace the active managed dir with a fresh
     * copy of SKILL.md + references/ only.
     *
     * @param  array<int, string>  $absoluteSkillDirs  Absolute skills-root dirs (e.g. base_path('.claude/skills')).
     * @param  string  $mode  One of InstallMode::modes().
     * @return array<int, string> The absolute managed dirs written (one per unique root).
     *
     * @throws InvalidArgumentException When $mode is not a supported mode.
     */
    public static function install(array $absoluteSkillDirs, string $mode): array
    {
        // 1. Reject an unknown mode loudly; downstream path resolution assumes a valid mode.
        if (! in_array($mode, InstallMode::modes(), true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported install mode [%s]; expected one of: %s.',
                    $mode,
                    implode(', ', InstallMode::modes()),
                ),
            );
        }

        $source = dirname(__DIR__, 2).'/resources/boost/skills/'.self::BASENAMES[$mode];
        $activeBasename = self::BASENAMES[$mode];
        $otherBasename = self::otherBasename($mode);

        $written = [];

        // 2. Dedupe roots by canonical path so a shared root (e.g. .agents/skills) is written once.
        foreach (self::uniqueRoots($absoluteSkillDirs) as $root) {
            File::ensureDirectoryExists($root);

            // 3. Self-heal a mode switch: drop the other mode's managed dir by exact name only.
            $otherManaged = $root.'/'.$otherBasename;

            if (File::isDirectory($otherManaged)) {
                File::deleteDirectory($otherManaged);
            }

            // 4. Replace the active managed dir in place (delete-before-copy), exact name only.
            $target = $root.'/'.$activeBasename;

            if (File::isDirectory($target)) {
                File::deleteDirectory($target);
            }

            File::ensureDirectoryExists($target);

            // 5. Copy ONLY the publishable files; never the SKILL.blade.php render source.
            File::copy($source.'/SKILL.md', $target.'/SKILL.md');
            File::copyDirectory($source.'/references', $target.'/references');

            $written[] = $target;
        }

        return $written;
    }

    /**
     * The managed basename of the mode that is NOT being installed (cleanup target).
     */
    private static function otherBasename(string $mode): string
    {
        return self::BASENAMES[$mode === 'mcp' ? 'cli' : 'mcp'];
    }

    /**
     * Collapse the given roots to unique canonical paths, preserving first-seen order.
     *
     * A root may not exist yet, so realpath() is used when available and the raw
     * path is the fallback key; either way duplicates of the same root copy once.
     *
     * @param  array<int, string>  $roots
     * @return array<int, string>
     */
    private static function uniqueRoots(array $roots): array
    {
        $seen = [];
        $unique = [];

        foreach ($roots as $root) {
            $canonical = realpath($root) ?: $root;

            if (isset($seen[$canonical])) {
                continue;
            }

            $seen[$canonical] = true;
            $unique[] = $root;
        }

        return $unique;
    }
}
