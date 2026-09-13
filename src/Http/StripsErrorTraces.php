<?php

namespace Anilcancakir\LaravelAgentMcp\Http;

use Laravel\Mcp\Server;

/**
 * Guarantees no stack trace ever leaves the MCP error path, even when app.debug is
 * true.
 *
 * The stock Laravel\Mcp\Server::handle() catches a thrown Throwable, reports it, and
 * then either sends a generic JSON-RPC error (app.debug=false) or re-throws the raw
 * exception (app.debug=true), which the HTTP transport renders with a full stack
 * trace. A production MCP endpoint must never expose that.
 *
 * Rather than reconstruct the error response ourselves (the JsonRpcResponse class
 * moved namespaces from laravel/mcp 0.6 to 0.7, so referencing it directly is
 * version-fragile), this concern forces app.debug off for the duration of handle().
 * The parent then always takes its own safe, non-debug branch and emits the
 * version-correct generic JSON-RPC error with no trace. The host's real app.debug is
 * restored immediately afterward.
 *
 * Since laravel/mcp 0.8.1, tool-call exceptions are caught one layer lower, in the
 * new ToolInvoker (routed through InteractsWithResponses::callHandler()), which
 * converts every Throwable to a Response::error(...) before it ever reaches this
 * trait's handle(). The outcome for tool calls is unchanged because ToolInvoker
 * already honors app.debug=false, but this trait is no longer the layer doing the
 * stripping for that path; it remains necessary for the non-tool methods
 * (ListTools, Initialize) that never go through ToolInvoker.
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
