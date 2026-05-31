<?php

use Anilcancakir\LaravelAgentMcp\Support\InstallMode;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;

/**
 * Absolute path to a shipped skill's SKILL.blade.php.
 */
function skillBladePath(string $skill): string
{
    return dirname(__DIR__, 3)."/resources/boost/skills/{$skill}/SKILL.blade.php";
}

/**
 * Render a skill blade through the same Blade pipeline boost uses.
 */
function renderSkillBlade(string $skill): string
{
    return Blade::render((string) file_get_contents(skillBladePath($skill)));
}

describe('Skill mode guard blades', function (): void {

    afterEach(function (): void {
        File::delete(InstallMode::path());
    });

    // -------------------------------------------------------------------------
    // agent-mcp-investigation SKILL.blade.php (active in mcp mode)
    // -------------------------------------------------------------------------

    it('emits investigation frontmatter when mode is mcp', function (): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'mcp', 'version' => 1]));

        expect(renderSkillBlade('agent-mcp-investigation'))->toContain('name: agent-mcp-investigation');
    });

    it('emits no investigation frontmatter when mode is cli', function (): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'cli', 'version' => 1]));

        $output = renderSkillBlade('agent-mcp-investigation');

        expect(trim($output))->not->toStartWith('---');
        expect($output)->not->toContain('name: agent-mcp-investigation');
    });

    it('treats an absent file as mcp (investigation active)', function (): void {
        File::delete(InstallMode::path());

        expect(renderSkillBlade('agent-mcp-investigation'))->toContain('name: agent-mcp-investigation');
    });

    // -------------------------------------------------------------------------
    // agent-mcp-cli SKILL.blade.php (active in cli mode)
    // -------------------------------------------------------------------------

    it('emits cli frontmatter when mode is cli', function (): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'cli', 'version' => 1]));

        expect(renderSkillBlade('agent-mcp-cli'))->toContain('name: agent-mcp-cli');
    });

    it('emits no cli frontmatter when mode is mcp', function (): void {
        File::put(InstallMode::path(), json_encode(['mode' => 'mcp', 'version' => 1]));

        $output = renderSkillBlade('agent-mcp-cli');

        expect(trim($output))->not->toStartWith('---');
        expect($output)->not->toContain('name: agent-mcp-cli');
    });

    it('treats an absent file as mcp (cli empty)', function (): void {
        File::delete(InstallMode::path());

        $output = renderSkillBlade('agent-mcp-cli');

        expect(trim($output))->not->toStartWith('---');
        expect($output)->not->toContain('name: agent-mcp-cli');
    });

});
