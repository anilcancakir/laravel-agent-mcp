<?php

namespace Anilcancakir\LaravelAgentMcp\Commands;

use Anilcancakir\LaravelAgentMcp\Cli\AbstractMcpCliCommand;
use Anilcancakir\LaravelAgentMcp\Cli\RemoteInvocationException;
use JsonException;
use RuntimeException;

/**
 * agent-mcp:call: invoke a single MCP tool from the shell and print its result.
 *
 * Arguments come as a single JSON blob (the positional `input`, or STDIN when omitted),
 * which agents handle more reliably than repeated key=value flags. The tool payload is
 * written to stdout (raw when piped, pretty when on a terminal unless --raw); diagnostics
 * go to stderr; the exit code is non-zero on a tool error, an unknown tool, malformed JSON,
 * a sensitive-tool tty refusal, or a remote transport failure.
 *
 * Mode is default-local (in-process, gated by the tool's own authorize()); it switches to
 * remote when a remote url is configured (committed url in .agent-mcp.json or AGENT_MCP_URL),
 * and --local / --remote force the choice. Sensitive default-OFF tools refuse to print to a
 * terminal without --allow-tty (scrollback safety).
 */
class CallToolCommand extends AbstractMcpCliCommand
{
    /** @var string */
    protected $signature = 'agent-mcp:call
        {tool : The tool name (e.g. db_schema)}
        {input? : JSON arguments object; omit to read STDIN}
        {--remote : Force remote mode (forward to the configured url: committed .agent-mcp.json url or AGENT_MCP_URL)}
        {--local : Force local mode (run in-process)}
        {--allow-tty : Allow a sensitive tool to print to a terminal}
        {--raw : Emit the raw payload without pretty-printing}';

    /** @var string */
    protected $description = 'Invoke a single agent-mcp tool from the CLI and print its JSON result.';

    /**
     * The STDIN source for the JSON arguments when the positional input is omitted.
     * Defaults to STDIN; injectable so tests can feed arguments without a tty.
     *
     * @var resource|null
     */
    private mixed $inputStream = null;

    /**
     * Override the STDIN source (tests inject an in-memory stream).
     *
     * @param  resource  $stream
     */
    public function usingInputStream(mixed $stream): void
    {
        $this->inputStream = $stream;
    }

    public function handle(): int
    {
        if (! $this->ensureEnabled()) {
            return self::FAILURE;
        }

        $tool = (string) $this->argument('tool');

        try {
            $arguments = $this->resolveArguments();
        } catch (JsonException) {
            $this->writeError('Invalid JSON arguments. Pass a JSON object as the input argument or on STDIN.');

            return self::FAILURE;
        }

        if (! $this->guardSensitiveTty($tool, (bool) $this->option('allow-tty'))) {
            return self::FAILURE;
        }

        try {
            $result = $this->resolveMode() === 'remote'
                ? $this->invokeRemote($tool, $arguments)
                : $this->invokeLocal($tool, $arguments);
        } catch (RemoteInvocationException|RuntimeException $exception) {
            // Generic message only; never echo the key or the input.
            $this->writeError($exception->getMessage());

            return self::FAILURE;
        }

        return $this->writeResult($result['payload'], $result['isError'], (bool) $this->option('raw'));
    }

    /**
     * Forward the call to the remote endpoint and normalize its result envelope to the
     * {payload, isError} shape writeResult expects (payload = the first text content).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{payload: string, isError: bool}
     */
    private function invokeRemote(string $tool, array $arguments): array
    {
        $result = $this->remoteClient()->callTool($tool, $arguments);

        $payload = $result['content'][0]['text'] ?? '';

        return [
            'payload' => is_string($payload) ? $payload : '',
            'isError' => (bool) ($result['isError'] ?? false),
        ];
    }

    /**
     * Resolve the JSON arguments: the positional input when present, else STDIN, decoded to
     * an array. An empty/absent source yields an empty argument set.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function resolveArguments(): array
    {
        $raw = $this->argument('input');

        if (! is_string($raw) || $raw === '') {
            $raw = $this->readStdin();
        }

        $raw = trim((string) $raw);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read JSON arguments from STDIN. Returns an empty string when STDIN is an interactive
     * terminal (nothing piped) so the command never blocks waiting for input.
     */
    private function readStdin(): string
    {
        $stream = $this->inputStream ?? STDIN;

        if ($this->inputStream === null && function_exists('stream_isatty') && @stream_isatty(STDIN)) {
            return '';
        }

        return (string) stream_get_contents($stream);
    }
}
