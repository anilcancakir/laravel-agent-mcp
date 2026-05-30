<?php

use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubArtisanServer;
use Anilcancakir\LaravelAgentMcp\Tests\Stubs\StubTokenUser;
use Anilcancakir\LaravelAgentMcp\Tools\RunArtisanTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\McpServiceProvider;

// run_artisan executes commands as the host app: a confused-deputy surface (Oracle IMP5).
// The allowlist is the WHOLE authorization for WHICH command runs. These tests prove the
// authoritative deny path (empty allowlist), exact-match command authority (no substring),
// explicit per-option permitting (an artisan command accepts destructive options even when
// its name looks benign), and the artisan-ability gate. Every bypass attempt must fail closed.

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request; not auto-loaded in the isolated
    // package test app (no package provider until Step 14), so register it explicitly.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.abilities.artisan', 'agent-mcp:artisan');
    config()->set('agent-mcp.tools.run_artisan', true);
    config()->set('agent-mcp.artisan.allowlist', []);
});

it('denies authoritatively in handle() when the allowlist is empty even if the tool is enabled', function (): void {
    // shouldRegister() hides an empty-allowlist tool (best-effort UX), so a server-pipeline
    // call would report "not found" rather than proving handle() denies. The Done-when
    // requires the deny to be authoritative IN handle(): invoke it directly with the user on
    // the guard, bypassing registration, and assert it returns an error Response.
    config()->set('agent-mcp.artisan.allowlist', []);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:artisan']);
    auth()->guard()->setUser($user);

    $response = app(RunArtisanTool::class)->handle(new Request(['command' => 'route:list']));

    expect($response->isError())->toBeTrue();
    expect((string) $response->content())->toBe('This command is not permitted.');
});

it('requires the artisan ability', function (): void {
    config()->set('agent-mcp.artisan.allowlist', ['route:list']);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:read']);

    StubArtisanServer::actingAs($user)
        ->tool(RunArtisanTool::class, ['command' => 'route:list'])
        ->assertHasErrors();
});

it('runs an allowlisted bare command', function (): void {
    config()->set('agent-mcp.artisan.allowlist', ['route:list']);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:artisan']);

    StubArtisanServer::actingAs($user)
        ->tool(RunArtisanTool::class, ['command' => 'route:list'])
        ->assertOk();
});

it('rejects a command that is not in the allowlist', function (): void {
    config()->set('agent-mcp.artisan.allowlist', ['route:list']);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:artisan']);

    StubArtisanServer::actingAs($user)
        ->tool(RunArtisanTool::class, ['command' => 'migrate:fresh'])
        ->assertHasErrors();
});

it('rejects a command by exact match, not substring or prefix', function (): void {
    config()->set('agent-mcp.artisan.allowlist', ['route:list']);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:artisan']);

    StubArtisanServer::actingAs($user)
        ->tool(RunArtisanTool::class, ['command' => 'route:list --json'])
        ->assertHasErrors();
});

it('rejects an option that the allowlist entry does not permit', function (): void {
    // A bare-string entry permits no options at all: an extra --force must be rejected.
    config()->set('agent-mcp.artisan.allowlist', ['route:list']);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:artisan']);

    StubArtisanServer::actingAs($user)
        ->tool(RunArtisanTool::class, [
            'command' => 'route:list',
            'arguments' => ['--force' => true],
        ])
        ->assertHasErrors();
});

it('runs an allowlisted command with an explicitly permitted option', function (): void {
    config()->set('agent-mcp.artisan.allowlist', [
        [
            'command' => 'route:list',
            'options' => ['--json'],
        ],
    ]);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:artisan']);

    StubArtisanServer::actingAs($user)
        ->tool(RunArtisanTool::class, [
            'command' => 'route:list',
            'arguments' => ['--json' => true],
        ])
        ->assertOk();
});

it('rejects an option not in the entry options list even when other options are permitted', function (): void {
    config()->set('agent-mcp.artisan.allowlist', [
        [
            'command' => 'route:list',
            'options' => ['--json'],
        ],
    ]);

    $user = new StubTokenUser(id: 1, abilities: ['agent-mcp:artisan']);

    StubArtisanServer::actingAs($user)
        ->tool(RunArtisanTool::class, [
            'command' => 'route:list',
            'arguments' => ['--force' => true],
        ])
        ->assertHasErrors();
});

it('hides the tool via shouldRegister when the allowlist is empty', function (): void {
    config()->set('agent-mcp.tools.run_artisan', true);
    config()->set('agent-mcp.artisan.allowlist', []);

    $tool = app(RunArtisanTool::class);

    expect($tool->eligibleForRegistration())->toBeFalse();
});
