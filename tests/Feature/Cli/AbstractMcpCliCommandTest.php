<?php

use Anilcancakir\LaravelAgentMcp\Cli\AbstractMcpCliCommand;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response as McpResponse;

// AbstractMcpCliCommand is the shared base for the agent-mcp CLI commands. These tests
// drive a concrete test double that exposes the protected surface and overrides the tool
// roster + tty detection, so the security/correctness core (master gate, local dispatch,
// isError exit code, instanceof guard, sensitive-tty guard, pretty/raw output) is proven
// without a real terminal or the full command-input lifecycle.

class StubOkTool
{
    public function name(): string
    {
        return 'stub_ok';
    }

    public function handle(McpRequest $request): McpResponse
    {
        return McpResponse::text('{"ok":true}');
    }
}

class StubErrorTool
{
    public function name(): string
    {
        return 'stub_err';
    }

    public function handle(McpRequest $request): McpResponse
    {
        // isError true but the content is plain text, identical in shape to a success payload.
        return McpResponse::error('denied');
    }
}

class StubBadShapeTool
{
    public function name(): string
    {
        return 'stub_bad';
    }

    /**
     * @return array<int, string>
     */
    public function handle(McpRequest $request): array
    {
        return ['not', 'a', 'response'];
    }
}

class HarnessCliCommand extends AbstractMcpCliCommand
{
    protected $signature = 'agent-mcp:test-harness {--local} {--remote} {--allow-tty} {--raw}';

    /** @var array<string, class-string> */
    public array $toolMap = [];

    public bool $tty = false;

    public function handle(): int
    {
        return self::SUCCESS;
    }

    protected function knownTools(): array
    {
        return $this->toolMap;
    }

    protected function stdoutIsTty(): bool
    {
        return $this->tty;
    }

    public function callEnsureEnabled(): bool
    {
        return $this->ensureEnabled();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{payload: string, isError: bool}
     */
    public function callInvokeLocal(string $name, array $arguments): array
    {
        return $this->invokeLocal($name, $arguments);
    }

    public function callGuardSensitiveTty(string $name, bool $allowTty): bool
    {
        return $this->guardSensitiveTty($name, $allowTty);
    }

    public function callWriteResult(string $payload, bool $isError, bool $rawFlag): int
    {
        return $this->writeResult($payload, $isError, $rawFlag);
    }
}

function harness(): HarnessCliCommand
{
    return new HarnessCliCommand;
}

function captureStream(): mixed
{
    return fopen('php://memory', 'rw+');
}

function readStream(mixed $stream): string
{
    rewind($stream);

    return (string) stream_get_contents($stream);
}

// --- master enabled gate ---

it('blocks when the package master switch is off', function (): void {
    config()->set('agent-mcp.enabled', false);

    $cmd = harness();
    $err = captureStream();
    $cmd->usingErrorStream($err);

    expect($cmd->callEnsureEnabled())->toBeFalse();
    expect(readStream($err))->toContain('disabled');
});

it('passes the gate when the package is enabled', function (): void {
    config()->set('agent-mcp.enabled', true);

    expect(harness()->callEnsureEnabled())->toBeTrue();
});

// --- local dispatch + exit-code signal ---

it('invokes a tool in-process and returns its payload with isError false', function (): void {
    $cmd = harness();
    $cmd->toolMap = ['stub_ok' => StubOkTool::class];

    $result = $cmd->callInvokeLocal('stub_ok', []);

    expect($result['payload'])->toBe('{"ok":true}');
    expect($result['isError'])->toBeFalse();
});

it('derives isError from the Response flag, not the content string', function (): void {
    $cmd = harness();
    $cmd->toolMap = ['stub_err' => StubErrorTool::class];

    $result = $cmd->callInvokeLocal('stub_err', []);

    // Content is plain text; only the isError flag distinguishes it from a success payload.
    expect($result['payload'])->toBe('denied');
    expect($result['isError'])->toBeTrue();
});

it('throws on an unknown tool name', function (): void {
    expect(fn () => harness()->callInvokeLocal('nope', []))
        ->toThrow(RuntimeException::class);
});

it('rejects a tool that returns a non-Response shape rather than fataling', function (): void {
    $cmd = harness();
    $cmd->toolMap = ['stub_bad' => StubBadShapeTool::class];

    expect(fn () => $cmd->callInvokeLocal('stub_bad', []))
        ->toThrow(RuntimeException::class);
});

// --- sensitive-tool tty guard ---

it('blocks a sensitive tool from printing to a real terminal without --allow-tty', function (): void {
    $cmd = harness();
    $cmd->tty = true;
    $err = captureStream();
    $cmd->usingErrorStream($err);

    expect($cmd->callGuardSensitiveTty('config_inspect', false))->toBeFalse();
    expect(readStream($err))->toContain('--allow-tty');
});

it('allows a sensitive tool to a terminal with --allow-tty', function (): void {
    $cmd = harness();
    $cmd->tty = true;

    expect($cmd->callGuardSensitiveTty('config_inspect', true))->toBeTrue();
});

it('allows a sensitive tool when output is piped (not a terminal)', function (): void {
    $cmd = harness();
    $cmd->tty = false;

    expect($cmd->callGuardSensitiveTty('config_inspect', false))->toBeTrue();
});

it('does not guard a non-sensitive tool', function (): void {
    $cmd = harness();
    $cmd->tty = true;

    expect($cmd->callGuardSensitiveTty('db_schema', false))->toBeTrue();
});

// --- output rendering + exit code ---

it('writes raw payload and returns SUCCESS when piped', function (): void {
    $cmd = harness();
    $cmd->tty = false;
    $out = captureStream();
    $cmd->usingOutputStream($out);

    $code = $cmd->callWriteResult('{"a":1}', false, false);

    expect($code)->toBe(0);
    expect(trim(readStream($out)))->toBe('{"a":1}');
});

it('pretty-prints JSON to a terminal and returns FAILURE on a tool error', function (): void {
    $cmd = harness();
    $cmd->tty = true;
    $out = captureStream();
    $cmd->usingOutputStream($out);

    $code = $cmd->callWriteResult('{"a":1}', true, false);

    expect($code)->toBe(1);
    expect(readStream($out))->toContain("{\n    \"a\": 1\n}");
});
