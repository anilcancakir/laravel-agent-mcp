<?php

namespace Anilcancakir\LaravelAgentMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authentication boundary for the MCP HTTP transport.
 *
 * Authenticates every request against a single server-admin key
 * (config('agent-mcp.key'), env AGENT_MCP_KEY). The check is fail-closed: when
 * the key is unset or empty the request is rejected BEFORE any comparison, which
 * closes the hash_equals('', '') fail-open (two empty strings compare equal).
 *
 * Rejections RETURN a bare 401 response and never throw, so app.debug can never
 * render a stack trace from this layer. The compare is constant-time
 * (hash_equals). The configured key is never logged or echoed.
 */
class KeyAuthMiddleware
{
    /**
     * Authenticate the request against the configured server-admin key.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('agent-mcp.key');

        // 1. Fail closed before any compare: an unset/empty key must never
        //    authenticate. This also closes hash_equals('', '') fail-open.
        if (! is_string($configured) || $configured === '') {
            return response('', 401);
        }

        // 2. Extract the presented credential. For the Authorization header use
        //    bearerToken() so only an exact "Bearer " prefix is accepted; any
        //    other configured header is read as its raw value.
        $presented = $this->presentedKey($request);

        // 3. Reject when nothing usable was presented.
        if (! is_string($presented) || $presented === '') {
            return response('', 401);
        }

        // 4. Constant-time compare; both operands are guaranteed non-empty here.
        return hash_equals($configured, $presented)
            ? $next($request)
            : response('', 401);
    }

    /**
     * Read the presented key from the configured header.
     *
     * When the header is Authorization, parse it strictly via bearerToken()
     * (exact "Bearer " prefix required); otherwise return the raw header value.
     */
    protected function presentedKey(Request $request): ?string
    {
        $header = config('agent-mcp.key_header', 'Authorization');

        if (strcasecmp($header, 'Authorization') === 0) {
            return $request->bearerToken();
        }

        return $request->header($header);
    }
}
