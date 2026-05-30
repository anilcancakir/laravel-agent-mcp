<?php

namespace Anilcancakir\LaravelAgentMcp\Auditing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Contracts\HasApiTokens;

/**
 * Records tool-invocation metadata to the configured audit log channel.
 *
 * What is logged: tool name, argument SHAPE (key names + PHP value types, never
 * values), caller identity (user id + Sanctum token id when available), and a
 * timestamp. Argument values and token strings are never written to the log.
 *
 * The caller (AbstractAgentTool, Step 8) is responsible for mapping raw argument
 * values to their shape before passing $argShape here. This class trusts that
 * contract and does not re-inspect the values.
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
     * @param  Authenticatable|null  $user  The authenticated principal, or null for anonymous.
     */
    public function record(string $tool, array $argShape, ?Authenticatable $user): void
    {
        // 1. Short-circuit when the operator has disabled auditing.
        if (! config('agent-mcp.audit.enabled')) {
            return;
        }

        // 2. Derive caller identity: user id and, when Sanctum is in use, the
        //    token id. Never the token string itself.
        $userId = $user?->getAuthIdentifier();
        $context = [
            'tool' => $tool,
            'arg_shape' => $argShape,
            'user_id' => $userId,
            'timestamp' => now()->toIso8601String(),
        ];

        // 3. Include the Sanctum personal-access-token id when one is bound to
        //    the current request. The id is the opaque model key, not the secret.
        //    The Sanctum contract guarantees currentAccessToken(); the concrete
        //    token is an Eloquent model (PersonalAccessToken), so we read its key
        //    through the Model contract rather than assuming an $id property.
        $token = $user instanceof HasApiTokens ? $user->currentAccessToken() : null;

        if ($token instanceof Model) {
            $context['token_id'] = $token->getKey();
        }

        // 4. Write to the dedicated audit channel so the entry is routable to a
        //    separate log sink without touching the application log.
        Log::channel(config('agent-mcp.audit.channel'))->info('mcp.tool_invoked', $context);
    }
}
