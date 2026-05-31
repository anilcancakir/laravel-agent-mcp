<?php

namespace Anilcancakir\LaravelAgentMcp\Support;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Flat registry of every AI agent target this package supports.
 *
 * An AgentTarget models one coding-agent toolchain: where it stores its
 * guideline file, where skills are published, and which project-root markers
 * indicate the agent is active in a given project. The registry is a plain
 * static list; no per-agent subclasses, no inheritance hierarchy.
 *
 * This class intentionally mirrors the shape of InstallMode: final, static
 * factory methods, no instantiation outside the class itself.
 */
final class AgentTarget
{
    /**
     * Construct one agent target descriptor.
     *
     * @param  string  $key  Snake-case identifier (e.g. claude_code).
     * @param  string  $displayName  Human-readable name shown in output and docs.
     * @param  string  $guidelinePath  Relative path of the agent's guideline file (e.g. CLAUDE.md).
     * @param  string  $skillPath  Relative directory where skills are published (e.g. .claude/skills).
     * @param  string[]  $detectionPaths  Relative project-root markers whose presence indicates the agent is in use.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $displayName,
        public readonly string $guidelinePath,
        public readonly string $skillPath,
        public readonly array $detectionPaths,
    ) {}

    /**
     * Return the full registry of supported agent targets.
     *
     * The 10 targets follow the laravel/boost agent parity table. Order is
     * preserved so callers can rely on index positions for tests and iteration.
     *
     * @return AgentTarget[]
     */
    public static function all(): array
    {
        return [
            new self(
                key: 'claude_code',
                displayName: 'Claude Code',
                guidelinePath: 'CLAUDE.md',
                skillPath: '.claude/skills',
                detectionPaths: ['.claude', 'CLAUDE.md'],
            ),
            new self(
                key: 'cursor',
                displayName: 'Cursor',
                guidelinePath: 'AGENTS.md',
                skillPath: '.cursor/skills',
                detectionPaths: ['.cursor'],
            ),
            new self(
                key: 'copilot',
                displayName: 'GitHub Copilot',
                guidelinePath: 'AGENTS.md',
                skillPath: '.github/skills',
                detectionPaths: ['.github'],
            ),
            new self(
                key: 'junie',
                displayName: 'Junie',
                guidelinePath: 'AGENTS.md',
                skillPath: '.junie/skills',
                detectionPaths: ['.junie'],
            ),
            new self(
                key: 'gemini',
                displayName: 'Gemini',
                guidelinePath: 'GEMINI.md',
                skillPath: '.agents/skills',
                detectionPaths: ['GEMINI.md', '.gemini'],
            ),
            new self(
                key: 'codex',
                displayName: 'Codex',
                guidelinePath: 'AGENTS.md',
                skillPath: '.agents/skills',
                detectionPaths: ['AGENTS.md'],
            ),
            new self(
                key: 'opencode',
                displayName: 'OpenCode',
                guidelinePath: 'AGENTS.md',
                skillPath: '.agents/skills',
                detectionPaths: ['AGENTS.md'],
            ),
            new self(
                key: 'amp',
                displayName: 'Amp',
                guidelinePath: 'AGENTS.md',
                skillPath: '.agents/skills',
                detectionPaths: ['AGENTS.md'],
            ),
            new self(
                key: 'kiro',
                displayName: 'Kiro',
                guidelinePath: 'AGENTS.md',
                skillPath: '.kiro/skills',
                detectionPaths: ['.kiro'],
            ),
            new self(
                key: 'antigravity',
                displayName: 'Antigravity',
                guidelinePath: 'AGENTS.md',
                skillPath: '.agents/skills',
                detectionPaths: ['AGENTS.md'],
            ),
        ];
    }

    /**
     * Resolve a subset of targets by their keys.
     *
     * Validates every requested key against all() before returning. Throws on
     * the first unknown key so the caller gets an actionable error rather than
     * a silent empty result.
     *
     * @param  string[]  $keys  One or more snake-case target keys.
     * @return AgentTarget[]
     *
     * @throws InvalidArgumentException When any key is not present in all().
     */
    public static function fromKeys(array $keys): array
    {
        $registry = [];

        foreach (self::all() as $target) {
            $registry[$target->key] = $target;
        }

        $validKeys = array_keys($registry);

        foreach ($keys as $key) {
            if (! array_key_exists($key, $registry)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Unknown agent target [%s]; valid keys are: %s.',
                        $key,
                        implode(', ', $validKeys),
                    ),
                );
            }
        }

        return array_values(
            array_filter($registry, fn (string $k) => in_array($k, $keys, true), ARRAY_FILTER_USE_KEY),
        );
    }

    /**
     * Auto-detect which agent targets are active in the current project.
     *
     * Checks each target's detectionPaths against base_path(). A target is
     * included in the result as soon as any one of its markers exists, avoiding
     * duplicates when multiple markers match (e.g. both .claude and CLAUDE.md).
     *
     * @return AgentTarget[]
     */
    public static function detect(): array
    {
        $detected = [];

        foreach (self::all() as $target) {
            foreach ($target->detectionPaths as $marker) {
                if (File::exists(base_path($marker))) {
                    $detected[] = $target;

                    // Stop checking markers for this target once one matches.
                    break;
                }
            }
        }

        return $detected;
    }
}
