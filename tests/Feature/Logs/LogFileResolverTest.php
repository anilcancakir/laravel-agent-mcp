<?php

declare(strict_types=1);

use Anilcancakir\LaravelAgentMcp\Logs\LogFileResolver;
use Anilcancakir\LaravelAgentMcp\Logs\LogPathException;

// LogFileResolver is the path-traversal-to-arbitrary-file-read boundary (the old
// system's documented gap). It resolves the active log channel to a concrete file
// path and MUST prove that path is contained within storage/logs BEFORE any read,
// rejecting traversal, symlinks, and absolute paths outside the log directory.

beforeEach(function (): void {
    $logsDir = storage_path('logs');

    if (! is_dir($logsDir)) {
        mkdir($logsDir, 0755, true);
    }
});

it('resolves a single channel to its configured path under storage/logs', function (): void {
    $path = storage_path('logs/single-test.log');
    config()->set('logging.channels.agent_single', [
        'driver' => 'single',
        'path' => $path,
    ]);
    config()->set('agent-mcp.logs.channel', 'agent_single');

    expect(app(LogFileResolver::class)->resolve())->toBe($path);
});

it('resolves a daily channel by appending the current date before the extension', function (): void {
    config()->set('logging.channels.agent_daily', [
        'driver' => 'daily',
        'path' => storage_path('logs/laravel.log'),
    ]);
    config()->set('agent-mcp.logs.channel', 'agent_daily');

    $expected = storage_path('logs/laravel-'.date('Y-m-d').'.log');

    expect(app(LogFileResolver::class)->resolve())->toBe($expected);
});

it('resolves a stack channel by recursing into the first file-backed sub-channel', function (): void {
    $path = storage_path('logs/stack-target.log');
    config()->set('logging.channels.agent_inner', [
        'driver' => 'single',
        'path' => $path,
    ]);
    config()->set('logging.channels.agent_stack', [
        'driver' => 'stack',
        'channels' => ['agent_inner'],
    ]);
    config()->set('agent-mcp.logs.channel', 'agent_stack');

    expect(app(LogFileResolver::class)->resolve())->toBe($path);
});

it('falls back to the application default channel when none is configured', function (): void {
    $path = storage_path('logs/default-channel.log');
    config()->set('logging.default', 'agent_default');
    config()->set('logging.channels.agent_default', [
        'driver' => 'single',
        'path' => $path,
    ]);
    config()->set('agent-mcp.logs.channel', null);

    expect(app(LogFileResolver::class)->resolve())->toBe($path);
});

it('rejects a configured path that traverses outside storage/logs', function (): void {
    config()->set('logging.channels.agent_evil', [
        'driver' => 'single',
        'path' => storage_path('logs/../../../../../../etc/passwd'),
    ]);
    config()->set('agent-mcp.logs.channel', 'agent_evil');

    app(LogFileResolver::class)->resolve();
})->throws(LogPathException::class);

it('rejects an absolute path entirely outside storage/logs', function (): void {
    config()->set('logging.channels.agent_abs', [
        'driver' => 'single',
        'path' => '/etc/passwd',
    ]);
    config()->set('agent-mcp.logs.channel', 'agent_abs');

    app(LogFileResolver::class)->resolve();
})->throws(LogPathException::class);

it('rejects a symlink inside storage/logs that points outside it', function (): void {
    $outside = sys_get_temp_dir().'/agent-mcp-secret-'.uniqid().'.txt';
    file_put_contents($outside, "secret host file\n");

    $link = storage_path('logs/sneaky.log');

    if (is_link($link) || is_file($link)) {
        unlink($link);
    }

    symlink($outside, $link);

    config()->set('logging.channels.agent_symlink', [
        'driver' => 'single',
        'path' => $link,
    ]);
    config()->set('agent-mcp.logs.channel', 'agent_symlink');

    try {
        app(LogFileResolver::class)->resolve();
    } finally {
        @unlink($link);
        @unlink($outside);
    }
})->throws(LogPathException::class);

it('rejects a stack channel with no file-backed sub-channel', function (): void {
    config()->set('logging.channels.agent_nullonly', [
        'driver' => 'monolog',
    ]);
    config()->set('logging.channels.agent_emptystack', [
        'driver' => 'stack',
        'channels' => ['agent_nullonly'],
    ]);
    config()->set('agent-mcp.logs.channel', 'agent_emptystack');

    app(LogFileResolver::class)->resolve();
})->throws(LogPathException::class);

it('rejects an unknown channel', function (): void {
    config()->set('agent-mcp.logs.channel', 'does_not_exist');

    app(LogFileResolver::class)->resolve();
})->throws(LogPathException::class);
