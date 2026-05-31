<?php

namespace Anilcancakir\LaravelAgentMcp\Cli;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Remote-mode client for the CLI: invokes a tool (or lists tools) on a REMOTE agent-mcp
 * HTTP endpoint instead of running it in-process. Mirrors StdioBridgeCommand's transport
 * discipline (Bearer key via the configured key header, TLS always on, the key only ever
 * in the Authorization header) but issues a single stateless JSON-RPC request rather than
 * bridging a stdin loop.
 *
 * Connection comes from operator ENV only (AGENT_MCP_URL + AGENT_MCP_KEY via Env::get),
 * never from config or request data, so a caller cannot redirect the credential. The
 * remote agent-mcp server accepts a single tools/call POST with no initialize handshake.
 *
 * On any failure (non-2xx, transport error, malformed body) a RemoteInvocationException is
 * thrown with a generic message: no response body, no request body, no key.
 */
class RemoteToolClient
{
    /**
     * Streamable HTTP protocol version advertised on every request. The remote server is
     * authoritative; this is the current default the bridge also advertises.
     */
    private const PROTOCOL_VERSION = '2025-06-18';

    /**
     * Whether remote mode is usable: both the URL and the key are present in the process
     * environment. Read at call time so the live env (not cached config) is authoritative.
     */
    public function configured(): bool
    {
        return $this->url() !== null && $this->key() !== null;
    }

    /**
     * Invoke a single tool on the remote endpoint and return its JSON-RPC result envelope
     * (the {content, isError} shape).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function callTool(string $name, array $arguments): array
    {
        return $this->result($this->post([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]));
    }

    /**
     * List the remote tools, following nextCursor pagination until exhausted, and return
     * the merged tool list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTools(): array
    {
        $tools = [];
        $cursor = null;

        do {
            $payload = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ];

            if ($cursor !== null) {
                $payload['params'] = ['cursor' => $cursor];
            }

            $result = $this->result($this->post($payload));

            $page = $result['tools'] ?? [];
            $tools = array_merge($tools, is_array($page) ? $page : []);

            $next = $result['nextCursor'] ?? null;
            $cursor = is_string($next) && $next !== '' ? $next : null;
        } while ($cursor !== null);

        return $tools;
    }

    /**
     * POST a JSON-RPC payload to the remote endpoint. Throws a generic RemoteInvocationException
     * on a missing config, a transport error, or a non-2xx response (status code only, never
     * the body or the key).
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(array $payload): Response
    {
        $url = $this->url();
        $key = $this->key();

        if ($url === null || $key === null) {
            throw new RemoteInvocationException('Remote mode is not configured: set AGENT_MCP_URL and AGENT_MCP_KEY.');
        }

        try {
            $response = Http::withHeaders($this->headers($key))
                ->withBody(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json')
                ->post($url);
        } catch (ConnectionException) {
            // Never surface the exception message: it can echo the resolved host/credential.
            throw new RemoteInvocationException('Remote transport error reaching the agent-mcp endpoint.');
        }

        if (! $response->successful()) {
            // Status code only: never the upstream body, which can carry sensitive material.
            throw new RemoteInvocationException('Remote returned HTTP '.$response->status().'.');
        }

        return $response;
    }

    /**
     * Decode the JSON-RPC result envelope from a response body, stripping a leading SSE
     * "data: " frame prefix when present. Throws on a malformed body or a missing result.
     *
     * @return array<string, mixed>
     */
    private function result(Response $response): array
    {
        $body = trim($response->body());

        if (str_starts_with($body, 'data: ')) {
            $body = trim(substr($body, 6));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RemoteInvocationException('Remote returned a malformed response.');
        }

        if (! is_array($decoded) || ! isset($decoded['result']) || ! is_array($decoded['result'])) {
            throw new RemoteInvocationException('Remote response is missing a result envelope.');
        }

        return $decoded['result'];
    }

    /**
     * The Streamable HTTP headers for a forwarded request. Authorization uses the configured
     * key_header name (default Authorization, always Bearer-prefixed) for symmetry with the
     * server side.
     *
     * @return array<string, string>
     */
    private function headers(string $key): array
    {
        $keyHeader = config('agent-mcp.key_header', 'Authorization');
        $keyHeader = is_string($keyHeader) && $keyHeader !== '' ? $keyHeader : 'Authorization';

        return [
            $keyHeader => 'Bearer '.$key,
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
        ];
    }

    /**
     * The remote endpoint URL from the process environment, or null when unset/empty.
     */
    private function url(): ?string
    {
        $url = Env::get('AGENT_MCP_URL');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The server key from the process environment, or null when unset/empty.
     */
    private function key(): ?string
    {
        $key = Env::get('AGENT_MCP_KEY');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
