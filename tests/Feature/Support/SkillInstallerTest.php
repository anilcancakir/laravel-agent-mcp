<?php

use Anilcancakir\LaravelAgentMcp\Support\SkillInstaller;
use Illuminate\Support\Facades\File;

/**
 * Absolute path to the temp skills-root the installer writes into during a test.
 *
 * Lives under base_path() so the Testbench harness owns it; cleaned in afterEach.
 */
function skillsRoot(): string
{
    return base_path('.skills-test-root');
}

describe('SkillInstaller', function (): void {

    beforeEach(function (): void {
        File::deleteDirectory(skillsRoot());
    });

    afterEach(function (): void {
        File::deleteDirectory(skillsRoot());
    });

    // -------------------------------------------------------------------------
    // mcp install: copies SKILL.md + references, never the blade
    // -------------------------------------------------------------------------

    it('installs the mcp skill dir with SKILL.md and references', function (): void {
        $root = skillsRoot();

        SkillInstaller::install([$root], 'mcp');

        expect(File::exists($root.'/agent-mcp-investigation/SKILL.md'))->toBeTrue()
            ->and(File::exists($root.'/agent-mcp-investigation/references/tools.md'))->toBeTrue();
    });

    it('never copies SKILL.blade.php into the installed mcp dir', function (): void {
        $root = skillsRoot();

        SkillInstaller::install([$root], 'mcp');

        expect(File::missing($root.'/agent-mcp-investigation/SKILL.blade.php'))->toBeTrue();
    });

    it('returns the managed dirs it wrote', function (): void {
        $root = skillsRoot();

        $written = SkillInstaller::install([$root], 'mcp');

        expect($written)->toBe([$root.'/agent-mcp-investigation']);
    });

    // -------------------------------------------------------------------------
    // cli install
    // -------------------------------------------------------------------------

    it('installs the cli skill dir with SKILL.md and references', function (): void {
        $root = skillsRoot();

        SkillInstaller::install([$root], 'cli');

        expect(File::exists($root.'/agent-mcp-cli/SKILL.md'))->toBeTrue()
            ->and(File::exists($root.'/agent-mcp-cli/references/commands.md'))->toBeTrue()
            ->and(File::missing($root.'/agent-mcp-cli/SKILL.blade.php'))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // mode switch self-heal: removes the other mode's dir
    // -------------------------------------------------------------------------

    it('removes the prior mode dir when switching mcp to cli', function (): void {
        $root = skillsRoot();

        SkillInstaller::install([$root], 'mcp');
        expect(File::isDirectory($root.'/agent-mcp-investigation'))->toBeTrue();

        SkillInstaller::install([$root], 'cli');

        expect(File::missing($root.'/agent-mcp-investigation'))->toBeTrue()
            ->and(File::exists($root.'/agent-mcp-cli/SKILL.md'))->toBeTrue();
    });

    it('removes the prior mode dir when switching cli to mcp', function (): void {
        $root = skillsRoot();

        SkillInstaller::install([$root], 'cli');
        expect(File::isDirectory($root.'/agent-mcp-cli'))->toBeTrue();

        SkillInstaller::install([$root], 'mcp');

        expect(File::missing($root.'/agent-mcp-cli'))->toBeTrue()
            ->and(File::exists($root.'/agent-mcp-investigation/SKILL.md'))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // user content preservation: never glob-delete
    // -------------------------------------------------------------------------

    it('preserves an unrelated user skill dir present before install', function (): void {
        $root = skillsRoot();
        File::ensureDirectoryExists($root.'/my-skill');
        File::put($root.'/my-skill/SKILL.md', 'user owned');

        SkillInstaller::install([$root], 'mcp');

        expect(File::exists($root.'/my-skill/SKILL.md'))->toBeTrue()
            ->and(File::get($root.'/my-skill/SKILL.md'))->toBe('user owned');
    });

    it('preserves a user dir whose name starts with agent-mcp- across a mode switch', function (): void {
        $root = skillsRoot();
        File::ensureDirectoryExists($root.'/agent-mcp-custom');
        File::put($root.'/agent-mcp-custom/SKILL.md', 'user owned');

        SkillInstaller::install([$root], 'mcp');
        SkillInstaller::install([$root], 'cli');

        expect(File::exists($root.'/agent-mcp-custom/SKILL.md'))->toBeTrue()
            ->and(File::get($root.'/agent-mcp-custom/SKILL.md'))->toBe('user owned');
    });

    // -------------------------------------------------------------------------
    // dedupe
    // -------------------------------------------------------------------------

    it('writes a single managed dir when the same root is passed twice', function (): void {
        $root = skillsRoot();

        $written = SkillInstaller::install([$root, $root], 'mcp');

        expect($written)->toBe([$root.'/agent-mcp-investigation']);
    });

    it('re-installing the same mode replaces in place without duplication', function (): void {
        $root = skillsRoot();

        SkillInstaller::install([$root], 'mcp');
        SkillInstaller::install([$root], 'mcp');

        expect(File::exists($root.'/agent-mcp-investigation/SKILL.md'))->toBeTrue()
            ->and(File::missing($root.'/agent-mcp-investigation/SKILL.blade.php'))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // mode validation
    // -------------------------------------------------------------------------

    it('throws InvalidArgumentException for an unsupported mode', function (): void {
        $thrown = null;

        try {
            SkillInstaller::install([skillsRoot()], 'bogus');
        } catch (InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeInstanceOf(InvalidArgumentException::class);
    });
});
