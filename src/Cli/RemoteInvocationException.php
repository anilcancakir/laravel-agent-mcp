<?php

namespace Anilcancakir\LaravelAgentMcp\Cli;

use RuntimeException;

/**
 * Thrown when a remote agent-mcp invocation fails (non-2xx, transport error, or a
 * malformed result envelope). The message is deliberately generic: it never carries
 * the upstream response body, the request body, or the server key, so a failure
 * cannot leak credentials or schema detail into CLI output or logs.
 */
class RemoteInvocationException extends RuntimeException
{
    //
}
