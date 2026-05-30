<?php

use Anilcancakir\LaravelAgentMcp\Tools\StorageInfoTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Tool;

// A minimal server that hosts only StorageInfoTool, keeping these tests isolated
// from AgentMcpServer.

/**
 * Inline stub server that hosts StorageInfoTool for this test file only.
 */
final class StorageInfoStubServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        StorageInfoTool::class,
    ];
}

// Recognizable disk credentials that MUST be stripped from the output.
const STORAGE_DISK_SECRET = 'AWS_SECRET_NEVER_LEAKS_7777';
const STORAGE_DISK_KEY = 'AWS_KEY_NEVER_LEAKS_8888';

beforeEach(function (): void {
    // laravel/mcp's provider populates the injected Request via method injection.
    app()->register(McpServiceProvider::class);

    config()->set('agent-mcp.tools.storage_info', true);
    config()->set('agent-mcp.audit.enabled', false);

    // An S3-shaped disk carrying credentials that must be stripped.
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => STORAGE_DISK_KEY,
        'secret' => STORAGE_DISK_SECRET,
        'token' => 'a-session-token',
        'password' => 'a-disk-password',
        'region' => 'eu-west-1',
        'bucket' => 'app-bucket',
        'root' => '',
        'visibility' => 'private',
    ]);
});

// --- tool-enabled gate ---

it('denies the call when storage_info is disabled in config', function (): void {
    config()->set('agent-mcp.tools.storage_info', false);

    StorageInfoStubServer::tool(StorageInfoTool::class, [])
        ->assertHasErrors();
});

// --- disk credentials stripped ---

it('strips key, secret, password and token from disk configs', function (): void {
    $response = StorageInfoStubServer::tool(StorageInfoTool::class, [])
        ->assertOk();

    // The disk and its non-secret attributes are reported.
    $response->assertSee('s3');
    $response->assertSee('eu-west-1');
    $response->assertSee('visibility');

    // The credentials are stripped.
    $response->assertDontSee(STORAGE_DISK_KEY);
    $response->assertDontSee(STORAGE_DISK_SECRET);
    $response->assertDontSee('a-session-token');
    $response->assertDontSee('a-disk-password');
});
