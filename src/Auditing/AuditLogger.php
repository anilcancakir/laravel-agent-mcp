<?php

namespace Anilcancakir\LaravelAgentMcp\Auditing;

use Illuminate\Support\Facades\Log;

/**
 * Records tool-invocation metadata to the configured audit log channel.
 *
 * What is logged: tool name, argument SHAPE (key names + PHP value types, never
 * values), and a timestamp. There is no per-caller identity to record: the server
 * authenticates a single server-admin key at the HTTP layer, so every invocation
 * is the same principal. Argument values are never written to the log, and the key
 * itself is NEVER logged in any form.
 *
 * The caller (AbstractAgentTool) is responsible for mapping raw argument values to
 * their shape before passing $argShape here. This class trusts that contract and
 * does not re-inspect the values.
 *
 * When config('agent-mcp.audit.enabled') is false the method is a no-op so
 * operators can silence the audit channel without touching application code.
 */
class AuditLogger
{
    /**
     * Record a tool invocation to the audit channel.
     *
     * @param  string  $tool  The MCP tool name (e.g. 'db_query').
     * @param  array<string, string>  $argShape  Argument shape map: key => PHP type name.
     *                                           Values must never appear here.
     */
    public function record(string $tool, array $argShape): void
    {
        // 1. Short-circuit when the operator has disabled auditing.
        if (! config('agent-mcp.audit.enabled')) {
            return;
        }

        // 2. Record the invocation shape and a timestamp only. There is no caller
        //    identity under the single-key model, and the key is never logged.
        $context = [
            'tool' => $tool,
            'arg_shape' => $argShape,
            'timestamp' => now()->toIso8601String(),
        ];

        // 3. Write to the dedicated audit channel so the entry is routable to a
        //    separate log sink without touching the application log.
        Log::channel(config('agent-mcp.audit.channel'))->info('mcp.tool_invoked', $context);
    }
}
