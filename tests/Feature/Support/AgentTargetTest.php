<?php

use Anilcancakir\LaravelAgentMcp\Support\AgentTarget;
use Illuminate\Support\Facades\File;

describe('AgentTarget', function (): void {

    afterEach(function (): void {
        File::deleteDirectory(base_path('.claude'));
        File::delete(base_path('CLAUDE.md'));
        File::deleteDirectory(base_path('.cursor'));
        File::deleteDirectory(base_path('.github'));
        File::deleteDirectory(base_path('.junie'));
        File::deleteDirectory(base_path('.kiro'));
        File::delete(base_path('GEMINI.md'));
        File::deleteDirectory(base_path('.gemini'));
        File::delete(base_path('AGENTS.md'));
        File::deleteDirectory(base_path('.agents'));
    });

    // -------------------------------------------------------------------------
    // all(): registry completeness
    // -------------------------------------------------------------------------

    it('returns exactly 10 targets from all()', function (): void {
        expect(AgentTarget::all())->toHaveCount(10);
    });

    it('returns targets with the expected keys', function (): void {
        $keys = array_map(fn (AgentTarget $t) => $t->key, AgentTarget::all());

        expect($keys)->toBe([
            'claude_code',
            'cursor',
            'copilot',
            'junie',
            'gemini',
            'codex',
            'opencode',
            'amp',
            'kiro',
            'antigravity',
        ]);
    });

    it('maps claude_code to CLAUDE.md guideline and .claude/skills skill path', function (): void {
        $target = AgentTarget::all()[0];

        expect($target->key)->toBe('claude_code')
            ->and($target->guidelinePath)->toBe('CLAUDE.md')
            ->and($target->skillPath)->toBe('.claude/skills');
    });

    it('maps cursor to AGENTS.md guideline and .cursor/skills skill path', function (): void {
        $target = AgentTarget::all()[1];

        expect($target->key)->toBe('cursor')
            ->and($target->guidelinePath)->toBe('AGENTS.md')
            ->and($target->skillPath)->toBe('.cursor/skills');
    });

    it('maps copilot to AGENTS.md guideline and .github/skills skill path', function (): void {
        $target = AgentTarget::all()[2];

        expect($target->key)->toBe('copilot')
            ->and($target->guidelinePath)->toBe('AGENTS.md')
            ->and($target->skillPath)->toBe('.github/skills');
    });

    it('maps junie to AGENTS.md guideline and .junie/skills skill path', function (): void {
        $target = AgentTarget::all()[3];

        expect($target->key)->toBe('junie')
            ->and($target->guidelinePath)->toBe('AGENTS.md')
            ->and($target->skillPath)->toBe('.junie/skills');
    });

    it('maps gemini to GEMINI.md guideline and .agents/skills skill path', function (): void {
        $target = AgentTarget::all()[4];

        expect($target->key)->toBe('gemini')
            ->and($target->guidelinePath)->toBe('GEMINI.md')
            ->and($target->skillPath)->toBe('.agents/skills');
    });

    it('maps codex to AGENTS.md guideline and .agents/skills skill path', function (): void {
        $target = AgentTarget::all()[5];

        expect($target->key)->toBe('codex')
            ->and($target->guidelinePath)->toBe('AGENTS.md')
            ->and($target->skillPath)->toBe('.agents/skills');
    });

    it('maps opencode to AGENTS.md guideline and .agents/skills skill path', function (): void {
        $target = AgentTarget::all()[6];

        expect($target->key)->toBe('opencode')
            ->and($target->guidelinePath)->toBe('AGENTS.md')
            ->and($target->skillPath)->toBe('.agents/skills');
    });

    it('maps amp to AGENTS.md guideline and .agents/skills skill path', function (): void {
        $target = AgentTarget::all()[7];

        expect($target->key)->toBe('amp')
            ->and($target->guidelinePath)->toBe('AGENTS.md')
            ->and($target->skillPath)->toBe('.agents/skills');
    });

    it('maps kiro to AGENTS.md guideline and .kiro/skills skill path', function (): void {
        $target = AgentTarget::all()[8];

        expect($target->key)->toBe('kiro')
            ->and($target->guidelinePath)->toBe('AGENTS.md')
            ->and($target->skillPath)->toBe('.kiro/skills');
    });

    it('maps antigravity to AGENTS.md guideline and .agents/skills skill path', function (): void {
        $target = AgentTarget::all()[9];

        expect($target->key)->toBe('antigravity')
            ->and($target->guidelinePath)->toBe('AGENTS.md')
            ->and($target->skillPath)->toBe('.agents/skills');
    });

    // -------------------------------------------------------------------------
    // fromKeys(): valid and invalid
    // -------------------------------------------------------------------------

    it('returns one target for a valid single key', function (): void {
        $result = AgentTarget::fromKeys(['claude_code']);

        expect($result)->toHaveCount(1)
            ->and($result[0]->key)->toBe('claude_code');
    });

    it('returns multiple targets for multiple valid keys', function (): void {
        $result = AgentTarget::fromKeys(['claude_code', 'cursor', 'copilot']);

        expect($result)->toHaveCount(3);
    });

    it('throws InvalidArgumentException for an unknown key', function (): void {
        $thrown = null;

        try {
            AgentTarget::fromKeys(['unknown_agent']);
        } catch (InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeInstanceOf(InvalidArgumentException::class);
    });

    it('includes the valid key list in the exception message', function (): void {
        $thrown = null;

        try {
            AgentTarget::fromKeys(['bogus']);
        } catch (InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->getMessage())->toContain('claude_code');
    });

    // -------------------------------------------------------------------------
    // detect(): base_path marker matching
    // -------------------------------------------------------------------------

    it('returns claude_code target when .claude directory exists', function (): void {
        File::makeDirectory(base_path('.claude'), 0755, true);

        $detected = AgentTarget::detect();
        $keys = array_map(fn (AgentTarget $t) => $t->key, $detected);

        expect($keys)->toContain('claude_code');
    });

    it('returns claude_code target when CLAUDE.md file exists', function (): void {
        File::put(base_path('CLAUDE.md'), '');

        $detected = AgentTarget::detect();
        $keys = array_map(fn (AgentTarget $t) => $t->key, $detected);

        expect($keys)->toContain('claude_code');
    });

    it('returns cursor target when .cursor directory exists', function (): void {
        File::makeDirectory(base_path('.cursor'), 0755, true);

        $detected = AgentTarget::detect();
        $keys = array_map(fn (AgentTarget $t) => $t->key, $detected);

        expect($keys)->toContain('cursor');
    });

    it('returns gemini target when GEMINI.md file exists', function (): void {
        File::put(base_path('GEMINI.md'), '');

        $detected = AgentTarget::detect();
        $keys = array_map(fn (AgentTarget $t) => $t->key, $detected);

        expect($keys)->toContain('gemini');
    });

    it('returns an empty array when no detection markers exist', function (): void {
        expect(AgentTarget::detect())->toBe([]);
    });
});
