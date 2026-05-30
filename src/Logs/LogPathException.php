<?php

namespace Anilcancakir\LaravelAgentMcp\Logs;

use RuntimeException;

/**
 * Raised when a log channel cannot be resolved to a safe, contained file path.
 *
 * The message is intentionally generic: it never echoes the offending path,
 * channel internals, or filesystem detail, so a rejection cannot be used to
 * probe the storage layout or confirm the existence of arbitrary host files
 * (the path-traversal-to-arbitrary-file-read gap this resolver closes).
 */
final class LogPathException extends RuntimeException
{
    public static function unresolvable(): self
    {
        return new self('The configured log channel does not resolve to a readable log file.');
    }

    public static function outsideLogDirectory(): self
    {
        return new self('The resolved log path is outside the permitted log directory and was rejected.');
    }
}
