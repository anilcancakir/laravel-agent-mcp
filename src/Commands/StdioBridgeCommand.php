<?php

namespace Anilcancakir\LaravelAgentMcp\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Local stdio MCP entrypoint that bridges a stdio MCP client to a REMOTE HTTP MCP
 * endpoint. The client (its `.mcp.json` `env`) supplies the connection via operator-set
 * ENV: AGENT_MCP_URL (the remote /agent-mcp URL) and AGENT_MCP_KEY (the server key).
 *
 * The bridge reads newline-delimited JSON-RPC from STDIN, POSTs each raw line to the
 * remote with a Bearer key, and writes the remote reply (plus a newline) to STDOUT and
 * nothing else. Diagnostics go to STDERR with the key scrubbed.
 *
 * Security boundary:
 *   - STDOUT carries pure MCP bytes only; every diagnostic goes to STDERR.
 *   - The key travels only in the Authorization header, never on stdout/stderr.
 *   - TLS verification is always on (no withoutVerifying()).
 *   - The remote URL/key are sourced from operator ENV only, never from stdin/request
 *     data, so a malicious peer cannot redirect the credential.
 */
class StdioBridgeCommand extends Command
{
    /** @var string */
    protected $signature = 'agent-mcp:stdio';

    /** @var string */
    protected $description = 'Bridge a local stdio MCP client to a remote HTTP MCP endpoint using AGENT_MCP_URL + AGENT_MCP_KEY.';

    /**
     * Streamable HTTP protocol version advertised on every forwarded request. The remote
     * server is authoritative and echoes its own supported version on the initialize reply;
     * the bridge advertises a current default and forwards the negotiated reply untouched.
     */
    private const PROTOCOL_VERSION = '2025-06-18';

    /**
     * The STDIN stream the loop reads JSON-RPC lines from. Defaults to STDIN; tests
     * inject an in-memory stream so the per-message forward is exercisable without a tty.
     *
     * @var resource|null
     */
    private mixed $inputStream = null;

    /**
     * The STDOUT stream MCP replies are written to. Defaults to STDOUT; injectable so
     * tests can assert the exact emitted bytes (direct stream writes bypass ob_start).
     *
     * @var resource|null
     */
    private mixed $outputStream = null;

    /**
     * The STDERR stream diagnostics are written to. Defaults to STDERR; injectable so
     * tests can assert the key never leaks into the diagnostic.
     *
     * @var resource|null
     */
    private mixed $errorStream = null;

    /**
     * The Mcp-Session-Id echoed back on requests after the remote returns one on
     * initialize. Null until the remote establishes a session.
     */
    private ?string $sessionId = null;

    /**
     * Override the STDIN source (tests inject an in-memory stream).
     *
     * @param  resource  $stream
     */
    public function usingInputStream(mixed $stream): void
    {
        $this->inputStream = $stream;
    }

    /**
     * Override the STDOUT sink (tests inject an in-memory stream to assert emitted bytes).
     *
     * @param  resource  $stream
     */
    public function usingOutputStream(mixed $stream): void
    {
        $this->outputStream = $stream;
    }

    /**
     * Override the STDERR sink (tests inject an in-memory stream to assert no key leak).
     *
     * @param  resource  $stream
     */
    public function usingErrorStream(mixed $stream): void
    {
        $this->errorStream = $stream;
    }

    public function handle(): int
    {
        // 1. Fail fast on missing operator ENV: a clear STDERR message, no stdout noise.
        //    Read process ENV directly via Env::get (NOT config()): these values are the
        //    client's .mcp.json env, deliberately not Laravel config keys, and must reflect
        //    the live process environment regardless of any cached config. Sourced from ENV
        //    only (never from stdin/request data) so a peer cannot point the credential at
        //    an attacker-controlled host.
        $url = Env::get('AGENT_MCP_URL');
        $key = Env::get('AGENT_MCP_KEY');

        if (! is_string($url) || $url === '') {
            $this->failStartup('AGENT_MCP_URL is not set. Set it in your .mcp.json env to the remote /agent-mcp URL.');

            return self::FAILURE;
        }

        if (! is_string($key) || $key === '') {
            $this->failStartup('AGENT_MCP_KEY is not set. Set it in your .mcp.json env to the server key.');

            return self::FAILURE;
        }

        // 2. Thin wrapper around the testable forward(): pump newline-delimited JSON-RPC
        //    lines from STDIN to the remote and echo each reply. fgets returns false at EOF.
        $stream = $this->inputStream();

        while (($line = fgets($stream)) !== false) {
            $line = rtrim($line, "\r\n");

            if ($line === '') {
                continue;
            }

            $this->writeStdout($this->forward($line, $url, $key));
        }

        return self::SUCCESS;
    }

    /**
     * Forward a single JSON-RPC line to the remote and return the line to emit on STDOUT
     * (without the trailing newline). On a non-2xx or transport error, returns a generic
     * JSON-RPC error object matching the request id and writes a SCRUBBED diagnostic to
     * STDERR. The key only ever appears in the Authorization header here.
     */
    public function forward(string $jsonRpcLine, string $url, string $key): string
    {
        try {
            $response = Http::withHeaders($this->forwardHeaders($key))
                ->withBody($jsonRpcLine, 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            // Transport failure (DNS, TLS, timeout). Surface a scrubbed reason only.
            $this->writeStderr('agent-mcp:stdio transport error: '.$exception->getMessage());

            return $this->errorResponse($jsonRpcLine, 'Remote transport error.');
        }

        if (! $response->successful()) {
            // Non-2xx from the remote. Never echo the upstream body or our request body;
            // both can carry sensitive material. Report only the status code.
            $this->writeStderr('agent-mcp:stdio remote returned HTTP '.$response->status().'.');

            return $this->errorResponse($jsonRpcLine, 'Remote returned an error response.');
        }

        // Capture the session id from the initialize handshake so later requests carry it.
        $sessionId = $response->header('Mcp-Session-Id');

        if ($sessionId !== '') {
            $this->sessionId = $sessionId;
        }

        return $response->body();
    }

    /**
     * Build the Streamable HTTP headers for a forwarded request. Authorization uses the
     * configured key_header name for symmetry with the server side (default Authorization,
     * always Bearer-prefixed). Mcp-Session-Id is attached only once the remote has issued one.
     *
     * @return array<string, string>
     */
    private function forwardHeaders(string $key): array
    {
        $keyHeader = config('agent-mcp.key_header', 'Authorization');
        $keyHeader = is_string($keyHeader) && $keyHeader !== '' ? $keyHeader : 'Authorization';

        $headers = [
            $keyHeader => 'Bearer '.$key,
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
        ];

        if ($this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        return $headers;
    }

    /**
     * Build a generic JSON-RPC error object, reusing the request id when the incoming
     * line is parseable so the client can correlate the failure. The message is generic
     * by design: no upstream body, no request body, no credential.
     */
    private function errorResponse(string $jsonRpcLine, string $message): string
    {
        $id = $this->parseRequestId($jsonRpcLine);

        $error = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => -32603,
                'message' => $message,
            ],
        ];

        return json_encode($error, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Extract the JSON-RPC request id from an incoming line, or null when the line is not
     * valid JSON or carries no id (e.g. a notification).
     */
    private function parseRequestId(string $jsonRpcLine): string|int|null
    {
        try {
            $decoded = json_decode($jsonRpcLine, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;

        return is_string($id) || is_int($id) ? $id : null;
    }

    /**
     * Write one MCP frame to STDOUT: the JSON line plus a single newline, nothing else.
     * Goes straight to the STDOUT stream (not the console OutputInterface) to guarantee
     * no formatting bytes contaminate the framing.
     */
    private function writeStdout(string $line): void
    {
        fwrite($this->outputStream(), $line."\n");
    }

    /**
     * Write a diagnostic to STDERR. Callers pass already-scrubbed messages; this never
     * receives the key or the Authorization header value.
     */
    private function writeStderr(string $message): void
    {
        fwrite($this->errorStream(), $message."\n");
    }

    /**
     * Emit a fatal startup diagnostic to STDERR only. Used for missing-ENV fast-fail so
     * no bytes ever reach STDOUT.
     */
    private function failStartup(string $message): void
    {
        $this->writeStderr('agent-mcp:stdio: '.$message);
    }

    /**
     * Resolve the STDIN stream, defaulting to the real STDIN when not injected.
     *
     * @return resource
     */
    private function inputStream(): mixed
    {
        return $this->inputStream ?? STDIN;
    }

    /**
     * Resolve the STDOUT stream, defaulting to the real STDOUT when not injected.
     *
     * @return resource
     */
    private function outputStream(): mixed
    {
        return $this->outputStream ?? STDOUT;
    }

    /**
     * Resolve the STDERR stream, defaulting to the real STDERR when not injected.
     *
     * @return resource
     */
    private function errorStream(): mixed
    {
        return $this->errorStream ?? STDERR;
    }
}
