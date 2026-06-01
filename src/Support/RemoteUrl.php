<?php

namespace Anilcancakir\LaravelAgentMcp\Support;

/**
 * Single source of truth for the "Bearer key only over TLS" rule.
 *
 * The CLI mode can route an agent's Bearer key to a remote endpoint declared by
 * a committed url in .agent-mcp.json (or the AGENT_MCP_URL env override). Sending
 * that secret over plaintext http would leak it on the wire, so the destination
 * must be TLS: https for any real host, with the sole exception of loopback http
 * (localhost / 127.0.0.1 / ::1), where traffic never leaves the machine and a
 * local dev server commonly speaks plain http.
 *
 * This validator is the ONE place that rule lives. InstallMode (write/read),
 * RemoteToolClient (the POST guard), and InstallCommand (the --url option) all
 * delegate here so the rule cannot drift between intake and send. It is a pure
 * boolean function of its argument: no env reads, no file reads, no rewriting.
 */
final class RemoteUrl
{
    /** Hosts allowed to speak plain http because traffic stays on the machine. */
    private const LOOPBACK_HOSTS = [
        'localhost',
        '127.0.0.1',
        '[::1]',
    ];

    /**
     * Decide whether $url is a safe remote target for the Bearer key.
     *
     * True only for a well-formed absolute http(s) URL with a host where either
     * the scheme is https, or the scheme is http AND the host is loopback. Null,
     * empty, malformed, missing-host, non-http schemes, and http to any
     * non-loopback host are all false.
     *
     * @param  string|null  $url  The candidate URL (committed value or env override).
     */
    public static function valid(?string $url): bool
    {
        // 1. Reject null/empty before parsing (parse_url is lax about empties).
        if ($url === null || $url === '') {
            return false;
        }

        // 2. parse_url returns false on a malformed URL; an absolute URL must
        //    also carry both a scheme and a host (rejects "javascript:..."
        //    and "https://" with no authority).
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        // 3. https is always allowed; the key is encrypted on the wire.
        if ($scheme === 'https') {
            return true;
        }

        // 4. http is allowed only to a loopback host (traffic never leaves the box).
        if ($scheme === 'http') {
            return in_array($host, self::LOOPBACK_HOSTS, true);
        }

        // 5. Any other scheme (ftp, javascript, typos) is rejected.
        return false;
    }
}
