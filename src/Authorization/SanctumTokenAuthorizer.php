<?php

declare(strict_types=1);

namespace Anilcancakir\LaravelAgentMcp\Authorization;

use Anilcancakir\LaravelAgentMcp\Contracts\AuthorizesAgentTools;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Laravel\Sanctum\TransientToken;

/**
 * Default authorizer: grants when a Sanctum personal access token carries the
 * required ability. This is the only Sanctum-coupled class in the package; the rest
 * of the code authorizes through the AuthorizesAgentTools contract, so a host without
 * Sanctum simply binds its own authorizer and never loads this class.
 *
 * Fail-closed rules:
 *   - No user, or an empty ability, denies.
 *   - The principal must be a Sanctum token holder. (instanceof against an absent
 *     Sanctum returns false, so this denies safely even when Sanctum is not installed
 *     and this class is used by mistake.)
 *   - A first-party session credential resolves to a TransientToken whose can()
 *     returns true for every ability; reject it so session auth cannot bypass scoping.
 *   - Otherwise defer to the token's own ability check.
 */
final class SanctumTokenAuthorizer implements AuthorizesAgentTools
{
    public function authorizes(?Authenticatable $user, string $ability): bool
    {
        if ($ability === '' || ! $user instanceof HasApiTokens) {
            return false;
        }

        if ($user->currentAccessToken() instanceof TransientToken) {
            return false;
        }

        return $user->tokenCan($ability);
    }
}
