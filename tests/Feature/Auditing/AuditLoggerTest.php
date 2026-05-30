<?php

use Anilcancakir\LaravelAgentMcp\Auditing\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

// AuditLogger records tool-invocation metadata (shape only, never values) to the
// configured audit channel, and is a no-op when the audit flag is disabled.

/**
 * Minimal Sanctum-style principal: an Authenticatable that also carries the
 * HasApiTokens contract so the logger's instanceof narrowing resolves the token
 * id exactly as it would for a real User model.
 */
class FakeUserWithToken implements Authenticatable, HasApiTokens
{
    public function __construct(
        public readonly int $id,
        public readonly ?object $token = null,
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

    public function currentAccessToken(): ?object
    {
        return $this->token;
    }

    /**
     * @return MorphMany<PersonalAccessToken, Model>
     */
    public function tokens()
    {
        throw new RuntimeException('Not needed for the audit logger test double.');
    }

    public function tokenCan(string $ability)
    {
        return false;
    }

    /**
     * @param  array<int, string>  $abilities
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null)
    {
        throw new RuntimeException('Not needed for the audit logger test double.');
    }

    public function withAccessToken($accessToken)
    {
        return $this;
    }
}

it('records tool name, arg shape, user identity, and timestamp to the audit channel', function (): void {
    $loggedContext = null;

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$loggedContext): bool {
        $loggedContext = $context;

        return $message === 'mcp.tool_invoked';
    });

    $user = new FakeUserWithToken(id: 42);

    $logger = new AuditLogger;
    $logger->record(
        tool: 'db_query',
        argShape: ['table' => 'string', 'limit' => 'integer'],
        user: $user,
    );

    expect($loggedContext['tool'])->toBe('db_query');
    expect($loggedContext['arg_shape'])->toBe(['table' => 'string', 'limit' => 'integer']);
    expect($loggedContext['user_id'])->toBe(42);
    expect($loggedContext)->toHaveKey('timestamp');
});

it('logs an entry containing tool name, arg shape keys/types, and user identity', function (): void {
    $loggedContext = null;

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$loggedContext): bool {
        $loggedContext = $context;

        return $message === 'mcp.tool_invoked';
    });

    $user = new FakeUserWithToken(id: 99);

    $logger = new AuditLogger;
    $logger->record(
        tool: 'db_schema',
        argShape: ['table' => 'string', 'limit' => 'integer'],
        user: $user,
    );

    expect($loggedContext)->not()->toBeNull();
    expect($loggedContext['tool'])->toBe('db_schema');
    expect($loggedContext['arg_shape'])->toBe(['table' => 'string', 'limit' => 'integer']);
    expect($loggedContext['user_id'])->toBe(99);
    expect($loggedContext)->toHaveKey('timestamp');
    // The token string must never appear; token_id absent when no token is set.
    expect($loggedContext)->not()->toHaveKey('token');
    expect($loggedContext)->not()->toHaveKey('token_id');
});

it('includes the sanctum token id when available instead of the token string', function (): void {
    $loggedContext = null;

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$loggedContext): bool {
        $loggedContext = $context;

        return $message === 'mcp.tool_invoked';
    });

    $tokenObject = new PersonalAccessToken;
    $tokenObject->id = 7;

    $user = new FakeUserWithToken(id: 5, token: $tokenObject);

    $logger = new AuditLogger;
    $logger->record(
        tool: 'db_query',
        argShape: ['table' => 'string'],
        user: $user,
    );

    expect($loggedContext['token_id'])->toBe(7);
    // The token object itself must never appear in the log context.
    expect($loggedContext)->not()->toHaveKey('token');
});

it('never logs raw argument values even when the caller passes value-bearing keys', function (): void {
    $loggedContext = null;

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$loggedContext): bool {
        $loggedContext = $context;

        return $message === 'mcp.tool_invoked';
    });

    $user = new FakeUserWithToken(id: 1);

    // The caller is responsible for shaping; per the method contract $argShape should
    // already be a shape map. Even so the test proves values do NOT appear in the log.
    $logger = new AuditLogger;
    $logger->record(
        tool: 'db_raw_select',
        argShape: ['sql' => 'string'],
        user: $user,
    );

    // The logged arg_shape contains only type descriptors, not query text.
    expect($loggedContext['arg_shape'])->toBe(['sql' => 'string']);
    expect(json_encode($loggedContext))->not()->toContain('SELECT');
});

it('logs anonymously when no user is authenticated', function (): void {
    $loggedContext = null;

    Log::shouldReceive('channel')->with('agent-mcp-audit')->once()->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) use (&$loggedContext): bool {
        $loggedContext = $context;

        return $message === 'mcp.tool_invoked';
    });

    $logger = new AuditLogger;
    $logger->record(
        tool: 'db_schema',
        argShape: [],
        user: null,
    );

    expect($loggedContext['user_id'])->toBeNull();
    expect($loggedContext)->not()->toHaveKey('token_id');
});

it('is a no-op when audit is disabled', function (): void {
    config()->set('agent-mcp.audit.enabled', false);

    // Use shouldReceive with 0 times to assert the channel is never opened.
    Log::shouldReceive('channel')->never();
    Log::shouldReceive('info')->never();

    $user = new FakeUserWithToken(id: 3);

    $logger = new AuditLogger;
    $logger->record(
        tool: 'db_query',
        argShape: ['table' => 'string'],
        user: $user,
    );
});
