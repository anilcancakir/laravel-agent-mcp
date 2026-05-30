<?php

declare(strict_types=1);

namespace Anilcancakir\LaravelAgentMcp\Http;

use Laravel\Mcp\Server;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * Guarantees no stack trace ever leaves the MCP error path, even when
 * app.debug is true (Oracle IMP6).
 *
 * The stock Laravel\Mcp\Server::handle() swallows a thrown Throwable into a
 * generic JSON-RPC error ONLY when app.debug is false; when app.debug is true it
 * re-throws the raw exception, which the HTTP transport then renders with its full
 * stack trace (file paths, framework internals, potentially secrets in arguments).
 * A production MCP endpoint must never expose that, regardless of the host app's
 * debug flag.
 *
 * This concern wraps the parent handle(): if anything escapes (i.e. the debug
 * re-throw fired), it is reported for the operator and replaced with the same
 * generic JSON-RPC -32603 error the non-debug path would have sent. The agent sees
 * a uniform "something went wrong" message with no internals; the real detail lives
 * in the application log via report().
 *
 * @phpstan-require-extends Server
 */
trait StripsErrorTraces
{
    public function handle(string $rawMessage): void
    {
        try {
            parent::handle($rawMessage);
        } catch (Throwable $throwable) {
            // The parent already sent the safe error when app.debug is false; reaching
            // here means the debug re-throw fired. Log the detail for the operator, then
            // emit the trace-free JSON-RPC error the agent is allowed to see.
            report($throwable);

            $this->transport->send(
                JsonRpcResponse::error(
                    null,
                    -32603,
                    'Something went wrong while processing the request.',
                )->toJson(),
            );
        }
    }
}
