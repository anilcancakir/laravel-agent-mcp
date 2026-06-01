<?php

namespace Anilcancakir\LaravelAgentMcp\Cli;

use Anilcancakir\LaravelAgentMcp\Server\AgentMcpServer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response as McpResponse;
use ReflectionProperty;
use RuntimeException;

/**
 * Shared base for the agent-mcp CLI commands (call / tools / schema). Centralizes the
 * security + correctness surface so the concrete commands stay thin:
 *
 *   - ensureEnabled(): honors the master config('agent-mcp.enabled') switch, mirroring the
 *     service provider's inert-when-disabled contract. The HTTP route is skipped when the
 *     package is disabled; the CLI must be too.
 *   - resolveMode(): default local; AGENT_MCP_URL present -> remote; --local / --remote force.
 *   - invokeLocal(): runs a tool in-process by mirroring laravel/mcp's CallTool dispatch.
 *     The tool's own handle() runs authorize() (the per-tool enable gate) + audit() +
 *     redaction first, so the CLI inherits every guard. The exit code comes from
 *     Response::isError() (NOT the content string, which is identical for an error payload),
 *     and a non-Response return is rejected loudly.
 *   - guardSensitiveTty(): a sensitive (default-OFF) tool refuses to print to a terminal
 *     without --allow-tty, so secrets are not written to scrollback unintentionally.
 *     Piped/redirected output is unaffected.
 *
 * stdout carries the tool payload only; diagnostics go to stderr (the MCP stdio contract).
 * Both streams are injectable so tests can assert the exact bytes on each, mirroring
 * StdioBridgeCommand.
 */
abstract class AbstractMcpCliCommand extends Command
{
    /**
     * Tools that are OFF by default and can surface secrets; printing their output to a
     * terminal requires an explicit --allow-tty acknowledgement.
     *
     * @var array<int, string>
     */
    public const SENSITIVE_TOOLS = [
        'run_artisan',
        'config_inspect',
        'db_slow_queries',
        'db_active_locks',
        'cache_keys',
    ];

    /**
     * The STDOUT sink for the tool payload. Defaults to STDOUT; injectable so tests assert
     * the exact emitted bytes (and so the raw payload is pipeable without console formatting).
     *
     * @var resource|null
     */
    private mixed $outputStream = null;

    /**
     * The STDERR sink for diagnostics. Defaults to STDERR; injectable for tests.
     *
     * @var resource|null
     */
    private mixed $errorStream = null;

    /**
     * Override the STDOUT sink (tests inject an in-memory stream to assert payload bytes).
     *
     * @param  resource  $stream
     */
    public function usingOutputStream(mixed $stream): void
    {
        $this->outputStream = $stream;
    }

    /**
     * Override the STDERR sink (tests inject an in-memory stream to assert diagnostics).
     *
     * @param  resource  $stream
     */
    public function usingErrorStream(mixed $stream): void
    {
        $this->errorStream = $stream;
    }

    /**
     * Whether the package master switch is on. When off, the command writes a stderr
     * diagnostic and the caller exits non-zero, mirroring the disabled-package contract.
     */
    protected function ensureEnabled(): bool
    {
        if (! (bool) config('agent-mcp.enabled', true)) {
            $this->writeError('agent-mcp is disabled (config agent-mcp.enabled is false).');

            return false;
        }

        return true;
    }

    /**
     * Resolve the invocation mode: --local / --remote force the choice; otherwise remote
     * when the remote endpoint is configured (AGENT_MCP_URL present), else local.
     */
    protected function resolveMode(): string
    {
        if ($this->option('local')) {
            return 'local';
        }

        if ($this->option('remote')) {
            return 'remote';
        }

        return $this->remoteClient()->configured() ? 'remote' : 'local';
    }

    /**
     * The remote-mode client (overridable in tests).
     */
    protected function remoteClient(): RemoteToolClient
    {
        return new RemoteToolClient;
    }

    /**
     * Map every registered tool's name to its class, read from the single source of truth
     * (AgentMcpServer's protected $tools roster) via reflection on the default value, so the
     * CLI never maintains a second registry and the server class is not modified.
     *
     * @return array<string, class-string>
     */
    protected function knownTools(): array
    {
        /** @var array<int, class-string> $classes */
        $classes = (new ReflectionProperty(AgentMcpServer::class, 'tools'))->getDefaultValue();

        $map = [];

        foreach ($classes as $class) {
            $map[App::make($class)->name()] = $class;
        }

        return $map;
    }

    /**
     * Invoke a tool in-process, mirroring laravel/mcp's CallTool dispatch. Resolving and
     * calling handle() runs the tool's own authorize() (enable gate) + audit() + redaction.
     * The return shape carries the payload string plus the authoritative error flag.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{payload: string, isError: bool}
     */
    protected function invokeLocal(string $name, array $arguments): array
    {
        $known = $this->knownTools();

        if (! isset($known[$name])) {
            throw new RuntimeException("Unknown tool: {$name}.");
        }

        $tool = App::make($known[$name]);
        $request = new McpRequest($arguments);

        $response = App::call([$tool, 'handle'], ['request' => $request]);

        // CallTool tolerates a ResponseFactory / iterable; the CLI supports only the
        // single-Response shape every package tool returns. Reject anything else loudly
        // rather than calling content() on the wrong type.
        if (! $response instanceof McpResponse) {
            throw new RuntimeException("Tool {$name} returned an unsupported response shape for the CLI.");
        }

        // The exit code follows isError(), never the content string: Response::error() and a
        // legitimate text payload stringify identically.
        return [
            'payload' => (string) $response->content(),
            'isError' => $response->isError(),
        ];
    }

    /**
     * Whether a sensitive tool may print to the current sink. A sensitive (default-OFF) tool
     * refuses a real terminal without --allow-tty so its output does not land in scrollback;
     * piped/redirected output is always allowed.
     */
    protected function guardSensitiveTty(string $name, bool $allowTty): bool
    {
        if (in_array($name, self::SENSITIVE_TOOLS, true) && $this->stdoutIsTty() && ! $allowTty) {
            $this->writeError(
                "Tool '{$name}' is sensitive and its output can expose secrets to terminal scrollback. "
                .'Re-run with --allow-tty to print to a terminal, or pipe/redirect the output.'
            );

            return false;
        }

        return true;
    }

    /**
     * Emit the tool payload to stdout and return the process exit code. The payload is
     * pretty-printed only when it is JSON AND stdout is a real terminal AND --raw was not
     * passed; piped output stays raw and byte-exact. The exit code is FAILURE on a tool error.
     */
    protected function writeResult(string $payload, bool $isError, bool $rawFlag): int
    {
        $out = $payload;

        if (! $rawFlag && $this->stdoutIsTty()) {
            $decoded = json_decode($payload, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $out = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        fwrite($this->outputStream(), $out."\n");

        return $isError ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Write a diagnostic to stderr. Callers pass already-generic messages; this never
     * receives the server key.
     */
    protected function writeError(string $message): void
    {
        fwrite($this->errorStream(), $message."\n");
    }

    /**
     * Whether stdout is a real terminal. When an output stream is injected (tests) the sink
     * is treated as non-interactive so output stays raw and byte-assertable. Protected so a
     * test double can force the terminal branch without an actual tty.
     */
    protected function stdoutIsTty(): bool
    {
        if ($this->outputStream !== null) {
            return false;
        }

        return function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }

    /**
     * Resolve the STDOUT sink, defaulting to the real STDOUT when not injected.
     *
     * @return resource
     */
    private function outputStream(): mixed
    {
        return $this->outputStream ?? STDOUT;
    }

    /**
     * Resolve the STDERR sink, defaulting to the real STDERR when not injected.
     *
     * @return resource
     */
    private function errorStream(): mixed
    {
        return $this->errorStream ?? STDERR;
    }
}
