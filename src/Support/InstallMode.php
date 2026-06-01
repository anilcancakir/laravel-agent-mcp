<?php

namespace Anilcancakir\LaravelAgentMcp\Support;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Single source of truth for the package install mode.
 *
 * The mode (MCP default vs CLI) is recorded in a committed project file,
 * .agent-mcp.json, and read at laravel-boost render time from inside the
 * shipped skill/guideline blades to inject only the active mode's content.
 *
 * current() is intentionally total: it NEVER throws. An absent, unreadable, or
 * malformed .agent-mcp.json resolves to the 'mcp' default. This is a defined
 * config resolution (no file == the default mode for every existing install),
 * NOT a swallowed error. Because blades call this during the consumer app's
 * boost render, a throw here would break rendering; the catch-to-'mcp' is the
 * contract, deliberately chosen, and documented as such.
 */
final class InstallMode
{
    /** Default mode applied when no valid recorded mode is found. */
    private const DEFAULT_MODE = 'mcp';

    /** Schema version stamped into a freshly written .agent-mcp.json. */
    private const VERSION = 1;

    /** Absolute path to the committed mode file in the consumer project root. */
    public static function path(): string
    {
        return base_path('.agent-mcp.json');
    }

    /**
     * The modes this package supports.
     *
     * @return array<int, string>
     */
    public static function modes(): array
    {
        return [
            'mcp',
            'cli',
        ];
    }

    /**
     * Resolve the active install mode.
     *
     * Returns the recorded mode when .agent-mcp.json exists and carries a known
     * string mode; returns the 'mcp' default on an absent, unreadable, malformed,
     * or invalid-mode file. An unrecognized "version" is tolerated (treated as
     * current). This method never throws (see the class docblock).
     */
    public static function current(): string
    {
        $path = self::path();

        // 1. Absent file resolves to the default mode (the common, expected case).
        if (! is_file($path)) {
            return self::DEFAULT_MODE;
        }

        // 2. Unreadable file resolves to the default; @ guards a race/permission read.
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return self::DEFAULT_MODE;
        }

        // 3. Malformed JSON or a non-object payload resolves to the default.
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return self::DEFAULT_MODE;
        }

        // 4. Accept only a known string mode; everything else is the default.
        //    The "version" key is intentionally not validated (forward-tolerant).
        $mode = $decoded['mode'] ?? null;

        if (is_string($mode) && in_array($mode, self::modes(), true)) {
            return $mode;
        }

        return self::DEFAULT_MODE;
    }

    /**
     * Resolve the committed remote URL from .agent-mcp.json.
     *
     * Mirrors current()'s never-throw contract: a missing, unreadable, malformed,
     * or non-conforming url value all resolve to null. Only a url that passes
     * RemoteUrl::valid() is returned, so callers never receive a plaintext http
     * target silently. The loud-error-on-intent path lives in RemoteToolClient
     * (Step 3), which reads the raw value separately; this method is the
     * "is a usable url committed?" query.
     *
     * @return string|null The committed url when valid, null otherwise.
     */
    public static function url(): ?string
    {
        $path = self::path();

        // 1. Absent file means no url committed.
        if (! is_file($path)) {
            return null;
        }

        // 2. Unreadable file resolves to no url; @ guards a race/permission read.
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        // 3. Malformed JSON or a non-object payload resolves to no url.
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return null;
        }

        // 4. Return the url only when it is a non-empty string that passes the TLS rule.
        $url = $decoded['url'] ?? null;

        if (is_string($url) && RemoteUrl::valid($url)) {
            return $url;
        }

        return null;
    }

    /**
     * Record the install mode to .agent-mcp.json.
     *
     * Writes pretty JSON ({"mode":$mode,"version":1}) to path(), and optionally
     * includes a committed remote url when $url is provided and valid. The file is
     * meant to be committed so the team, CI, and boost all resolve one mode. When
     * $url is given it must pass RemoteUrl::valid(); an invalid url fails loudly
     * before any file is written (the url is a credential-routing decision; a bad
     * scheme must never silently reach the JSON).
     *
     * @param  string  $mode  One of modes().
     * @param  string|null  $url  Optional remote endpoint url; must pass RemoteUrl::valid() when given.
     *
     * @throws InvalidArgumentException When $mode is not a supported mode, or $url is given and invalid.
     */
    public static function write(string $mode, ?string $url = null): void
    {
        // 1. Validate mode before touching the filesystem.
        if (! in_array($mode, self::modes(), true)) {
            throw new InvalidArgumentException(
                sprintf('Unsupported install mode [%s]; expected one of: %s.', $mode, implode(', ', self::modes())),
            );
        }

        // 2. Validate url when provided; reject before write so the JSON never
        //    carries a plaintext http target (the url routes the Bearer key).
        if ($url !== null && ! RemoteUrl::valid($url)) {
            throw new InvalidArgumentException(
                'The provided url is not a valid remote endpoint; it must be an https url (or http for loopback only).',
            );
        }

        // 3. Build the payload; include the url key only when a valid url was given.
        $data = [
            'mode' => $mode,
            'version' => self::VERSION,
        ];

        if ($url !== null) {
            $data['url'] = $url;
        }

        $payload = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if (File::put(self::path(), $payload) === false) {
            throw new RuntimeException(sprintf('Unable to write the install mode file at %s.', self::path()));
        }
    }
}
