<?php

declare(strict_types=1);

namespace Anilcancakir\LaravelAgentMcp\Tests\Stubs;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * Sanctum-style principal for the AbstractAgentTool tests.
 *
 * Implements Authenticatable so it can be set on the auth guard via actingAs(),
 * and HasApiTokens so the base tool's tokenCan() ability check resolves exactly
 * as it would against a real User model. The granted abilities are injected per
 * test so the deny and allow paths can both be exercised.
 */
class StubTokenUser implements Authenticatable, HasApiTokens
{
    /**
     * @param  array<int, string>  $abilities
     */
    public function __construct(
        public readonly int $id,
        public readonly array $abilities = [],
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    public function tokenCan(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    public function currentAccessToken(): ?object
    {
        return null;
    }

    /**
     * @return MorphMany<PersonalAccessToken, Model>
     */
    public function tokens()
    {
        throw new RuntimeException('Not needed for the AbstractAgentTool test double.');
    }

    /**
     * @param  array<int, string>  $abilities
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null)
    {
        throw new RuntimeException('Not needed for the AbstractAgentTool test double.');
    }

    public function withAccessToken($accessToken)
    {
        return $this;
    }
}
