<?php

declare(strict_types=1);

namespace Anilcancakir\LaravelAgentMcp\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides whether an authenticated principal may invoke an agent tool requiring a
 * given ability. This is the package's pluggable authorization seam: authentication
 * (who the user is) is the route middleware's job, and this contract answers the
 * separate question of whether that user is allowed the requested ability.
 *
 * The package ships a Sanctum-based default (SanctumTokenAuthorizer). A host that
 * authenticates differently (Passport scopes, a custom token guard, etc.) binds its
 * own implementation via config('agent-mcp.authorizer'); the plugin then carries no
 * hard dependency on any particular auth package.
 */
interface AuthorizesAgentTools
{
    /**
     * Whether $user is authorized for a tool call requiring $ability.
     *
     * Implementations MUST fail closed: return false for a null user, an empty
     * ability, or any principal whose credential cannot be confirmed to carry the
     * ability. Returning true is an explicit grant.
     */
    public function authorizes(?Authenticatable $user, string $ability): bool;
}
