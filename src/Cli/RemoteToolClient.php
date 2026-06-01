<?php

namespace Anilcancakir\LaravelAgentMcp\Cli;

use Anilcancakir\LaravelAgentMcp\Support\InstallMode;
use Anilcancakir\LaravelAgentMcp\Support\RemoteUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Remote-mode client for the CLI: invokes a tool (or lists tools) on a REMOTE agent-mcp
 * HTTP endpoint instead of running it in-process. Mirrors StdioBridgeCommand's transport
 * discipline (Bearer key via the configured key header, the key only ever in the
 * Authorization header) but issues a single stateless JSON-RPC request rather than
 * bridging a stdin loop.
 *
 * The remote URL is single-sourced through rawUrl(): the AGENT_MCP_URL env override wins,
 * else the committed url in .agent-mcp.json (InstallMode::url()). The key is env-only
 * (AGENT_MCP_KEY via Env::get), never read from config, a file, or request data, so a
 * caller cannot redirect or exfiltrate the credential. The remote agent-mcp server accepts
 * a single tools/call POST with no initialize handshake.
 *
 * TLS is enforced at the POST, not assumed: before sending, post() rejects a resolved url
 * that does not pass RemoteUrl::valid() (https, or http for loopback only) so the Bearer
 * key never travels in plaintext. A configured-but-unusable url errors loudly rather than
 * silently downgrading to local.
 *
 * On any failure (missing config, bad scheme, non-2xx, transport error, malformed body) a
 * RemoteInvocationException is thrown with a generic message: no url, no response body, no
 * request body, no key.
 */
class RemoteToolClient
{
    /**
     * Streamable HTTP protocol version advertised on every request. The remote server is
     * authoritative; this is the current default the bridge also advertises.
     */
    private const PROTOCOL_VERSION = '2025-06-18';

    /**
     * Whether remote intent is present: a remote url is resolvable (env override or the
     * committed file). The key is intentionally NOT part of this gate so a url-without-key
     * routes to remote and surfaces a loud error in post(), rather than silently running
     * local. Read at call time so the live env/file (not cached config) is authoritative.
     */
    public function configured(): bool
    {
        return $this->rawUrl() !== null;
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
        $url = $this->rawUrl();

        // 1. No remote intent at all: neither env override nor committed url.
        if ($url === null) {
            throw new RemoteInvocationException('Remote mode is not configured: set AGENT_MCP_URL and AGENT_MCP_KEY.');
        }

        // 2. A url is present but fails the TLS rule (e.g. a plaintext http env override).
        //    Reject before sending so the Bearer key never travels over plaintext; the
        //    message names neither the url nor the scheme value.
        if (! RemoteUrl::valid($url)) {
            throw new RemoteInvocationException('Remote endpoint must be an https URL.');
        }

        // 3. The key is env-only and mandatory; fail loudly before any request.
        $key = $this->key();

        if ($key === null) {
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
     * Resolve the remote endpoint URL from the single source of truth: the AGENT_MCP_URL
     * env override (trimmed, non-empty) wins, else the committed url in .agent-mcp.json.
     *
     * The env branch is read RAW (not filtered through RemoteUrl) so a bad-scheme env
     * override stays non-null and reaches the loud TLS guard in post(). InstallMode::url()
     * already filters the committed value through RemoteUrl::valid(), so a hand-edited
     * bad-scheme committed url reads as null here (no remote intent). This is the only
     * place env-then-file resolution lives; configured() and post() both consume it.
     */
    private function rawUrl(): ?string
    {
        $env = Env::get('AGENT_MCP_URL');

        if (is_string($env)) {
            $trimmed = trim($env);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return InstallMode::url();
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
