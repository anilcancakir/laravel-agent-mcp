<?php

declare(strict_types=1);

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
    public static function notReadOnlySelect(): self
    {
        return new self('The query was rejected: only a single read-only SELECT statement is allowed.');
    }
}
