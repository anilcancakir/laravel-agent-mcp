<?php

declare(strict_types=1);

namespace Anilcancakir\LaravelAgentMcp\Http;

use Laravel\Mcp\Server;

/**
 * Guarantees no stack trace ever leaves the MCP error path, even when app.debug is
 * true (Oracle IMP6).
 *
 * The stock Laravel\Mcp\Server::handle() catches a thrown Throwable, reports it, and
 * then either sends a generic JSON-RPC error (app.debug=false) or re-throws the raw
 * exception (app.debug=true), which the HTTP transport renders with a full stack
 * trace. A production MCP endpoint must never expose that.
 *
 * Rather than reconstruct the error response ourselves (the JsonRpcResponse class
 * moved namespaces between laravel/mcp 0.6 and 0.7, so referencing it directly is
 * version-fragile), this concern forces app.debug off for the duration of handle().
 * The parent then always takes its own safe, non-debug branch and emits the
 * version-correct generic JSON-RPC error with no trace. The host's real app.debug is
 * restored immediately afterward.
 *
 * @phpstan-require-extends Server
 *
 * @mixin Server
 */
trait StripsErrorTraces
{
    public function handle(string $rawMessage): void
    {
        $config = app('config');
        $originalDebug = $config->get('app.debug');

        $config->set('app.debug', false);

        try {
            parent::handle($rawMessage);
        } finally {
            $config->set('app.debug', $originalDebug);
        }
    }
}
