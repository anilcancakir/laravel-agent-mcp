<?php

declare(strict_types=1);

namespace Anilcancakir\LaravelAgentMcp\Logs;

/**
 * Resolves the active log channel to a single concrete file path AND proves that
 * path is contained within storage/logs before any read happens.
 *
 * This is the package's path-traversal-to-arbitrary-file-read boundary (the old
 * system's documented gap: a misimplemented canonicalization leaked arbitrary
 * host files). The containment check is the security crux:
 *
 *   1. realpath() only canonicalizes EXISTING paths. A daily channel's dated file
 *      may not exist yet, so we canonicalize the PARENT DIRECTORY (which Laravel
 *      creates) and re-append the basename. This collapses any '..' segments in
 *      the directory portion before the comparison.
 *   2. The resolved path must equal the canonical log root or sit beneath it with
 *      a directory-separator boundary (prefix matching alone would accept a
 *      sibling like '/storage/logs-evil', so the separator is mandatory).
 *   3. When the target file itself exists we realpath() it too, so a symlink
 *      placed inside storage/logs that points outside is caught (the directory
 *      check passes for the link's location, but the link target escapes).
 *
 * Any failure throws a non-leaky LogPathException; the configured path is never
 * echoed back.
 */
final class LogFileResolver
{
    /**
     * Resolve the configured (or default) log channel to a contained file path.
     *
     * @throws LogPathException When the channel is not file-backed or the path
     *                          escapes storage/logs.
     */
    public function resolve(): string
    {
        // 1. Pick the configured channel, falling back to the app default.
        $channel = $this->configuredChannel();

        // 2. Walk channel config (recursing through stacks) to a single path.
        $path = $this->resolveChannelPath($channel);

        if ($path === null) {
            throw LogPathException::unresolvable();
        }

        // 3. Prove containment within storage/logs BEFORE any caller reads it.
        return $this->assertContained($path);
    }

    /**
     * The channel name to resolve: the package override or the logging default.
     */
    private function configuredChannel(): string
    {
        $configured = config('agent-mcp.logs.channel');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $default = config('logging.default');

        return is_string($default) ? $default : 'single';
    }

    /**
     * Resolve a channel name to its backing file path, or null when the channel
     * is not file-backed (or resolves to no file via its stack).
     *
     * @param  array<string, string>  $visited  Guard against cyclic stacks.
     */
    private function resolveChannelPath(string $channel, array $visited = []): ?string
    {
        // Reject cyclic stack references (a stack listing itself, directly or via
        // another stack) rather than recursing until the stack overflows.
        if (isset($visited[$channel])) {
            return null;
        }

        $visited[$channel] = $channel;

        $config = config("logging.channels.{$channel}");

        if (! is_array($config)) {
            return null;
        }

        $driver = $config['driver'] ?? null;

        return match ($driver) {
            'single' => $this->pathFrom($config),
            'daily' => $this->dailyPathFrom($config),
            'stack' => $this->firstFileBackedSubChannel($config, $visited),
            default => null,
        };
    }

    /**
     * Extract a non-empty string 'path' from a channel config.
     *
     * @param  array<string, mixed>  $config
     */
    private function pathFrom(array $config): ?string
    {
        $path = $config['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Daily channels rotate into '<name>-Y-m-d.<ext>'; build today's file name the
     * same way Monolog's RotatingFileHandler does (date inserted before the final
     * extension, or appended when there is no extension).
     *
     * @param  array<string, mixed>  $config
     */
    private function dailyPathFrom(array $config): ?string
    {
        $path = $this->pathFrom($config);

        if ($path === null) {
            return null;
        }

        $date = date('Y-m-d');
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === '') {
            return "{$path}-{$date}";
        }

        $withoutExtension = substr($path, 0, -(strlen($extension) + 1));

        return "{$withoutExtension}-{$date}.{$extension}";
    }

    /**
     * Recurse into a stack's listed sub-channels and return the first one that
     * resolves to a file path.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $visited
     */
    private function firstFileBackedSubChannel(array $config, array $visited): ?string
    {
        $channels = $config['channels'] ?? [];

        if (! is_array($channels)) {
            return null;
        }

        foreach ($channels as $sub) {
            if (! is_string($sub)) {
                continue;
            }

            $path = $this->resolveChannelPath($sub, $visited);

            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Canonicalize the resolved path and assert it lives under storage/logs.
     *
     * @throws LogPathException
     */
    private function assertContained(string $path): string
    {
        $root = realpath($this->logRoot());

        if ($root === false) {
            // No log directory on disk yet means nothing can be safely read.
            throw LogPathException::unresolvable();
        }

        $canonical = $this->canonicalize($path);

        if (! $this->isWithin($canonical, $root)) {
            throw LogPathException::outsideLogDirectory();
        }

        // When the file already exists, re-canonicalize it directly so a symlink
        // inside storage/logs pointing outside is caught (its parent directory
        // check passes, but the link target escapes the root).
        if (is_file($canonical)) {
            $real = realpath($canonical);

            if ($real === false || ! $this->isWithin($real, $root)) {
                throw LogPathException::outsideLogDirectory();
            }

            return $real;
        }

        return $canonical;
    }

    /**
     * Canonicalize a possibly-not-yet-existing path by resolving its parent
     * directory (which collapses '..' segments) and re-appending the basename.
     *
     * @throws LogPathException When the parent directory does not exist.
     */
    private function canonicalize(string $path): string
    {
        $directory = dirname($path);
        $realDirectory = realpath($directory);

        if ($realDirectory === false) {
            // A non-existent parent directory cannot be proven contained.
            throw LogPathException::outsideLogDirectory();
        }

        return $realDirectory.DIRECTORY_SEPARATOR.basename($path);
    }

    /**
     * Whether $candidate is the root itself or sits beneath it. The trailing
     * separator on the root prevents a sibling such as '<root>-evil' from passing
     * a naive prefix check.
     */
    private function isWithin(string $candidate, string $root): bool
    {
        if ($candidate === $root) {
            return true;
        }

        return str_starts_with($candidate, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * The canonical log root all resolved paths must live under.
     */
    private function logRoot(): string
    {
        return storage_path('logs');
    }
}
