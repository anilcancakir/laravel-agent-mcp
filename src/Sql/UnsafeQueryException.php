<?php

namespace Anilcancakir\LaravelAgentMcp\Sql;

use RuntimeException;

/**
 * Raised when a raw SQL string fails the read-only SELECT allowlist.
 *
 * The message is intentionally generic: it never echoes the offending SQL,
 * matched token, or driver detail, so a rejection cannot be used to probe the
 * validator or leak the attempted query back to the caller.
 */
final class UnsafeQueryException extends RuntimeException
{
    /** Return fixed recovery guidance without exposing SQL or parser details. */
    public static function unsupportedSyntax(): self
    {
        return new self(
            'The SELECT syntax is not supported by the read-only validator. '
            .'Use simple aliases with AS, quote reserved-word aliases, and prefer CAST, LIMIT and window functions. '
            .'Simplify the query and retry.'
        );
    }

    public static function notReadOnlySelect(): self
    {
        return new self('The query was rejected: only a single read-only SELECT statement is allowed.');
    }
}
