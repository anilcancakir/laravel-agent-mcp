<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Anilcancakir\LaravelAgentMcp\Auditing\AuditLogger;
use Anilcancakir\LaravelAgentMcp\Contracts\AuthorizesAgentTools;
use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Anilcancakir\LaravelAgentMcp\Support\OutputRedactor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Shared base for every agent MCP tool: the authorization hub plus the common
 * readonly / redaction / audit pipeline the 5 concrete tools inherit.
 *
 * Verified against the INSTALLED laravel/mcp source (>=0.7 <0.8), since docs and
 * source disagree on these pre-1.0 details (Research Summary CAUTION):
 *   - Tool::handle is NOT declared on the base; it is invoked by CallTool via
 *     Container::call([$tool, 'handle']) (method injection). Subclasses declare
 *     their own handle(Request $request): Response and resolve extra dependencies
 *     by type-hint. The container also resolves the tool instance itself
 *     (ServerContext::resolvePrimitives + PendingTestResponse::resolvePrimitive),
 *     so CONSTRUCTOR injection of the three collaborators works.
 *   - shouldRegister is NOT declared on the base; Primitive::eligibleForRegistration
 *     calls it via Container::call when method_exists, so its arguments are
 *     method-injected and its presence is optional.
 *   - The Sanctum user is reached via the route's auth guard (auth()->user()),
 *     NOT via a request-passed user. Laravel\Mcp\Request::user() also delegates to
 *     the auth resolver, but the security model deliberately reads the guard
 *     directly so it is independent of whatever request object the mcp layer passes.
 *
 * Security model (Oracle IMP5: hiding is not authorization):
 *   - authorize() is the AUTHORITATIVE check. Every subclass handle() calls it
 *     FIRST and returns its denial Response when non-null, before doing any work.
 *   - shouldRegister() is best-effort UX only: it hides a disabled tool, and a
 *     tool the current token cannot use, from the tool list. Security never
 *     depends on it; an always-registered tool is still denied by handle().
 *
 * requiredAbility() contract: a subclass returns the config ability KEY (e.g.
 * 'read' or 'artisan'); the base resolves it to the concrete ability string via
 * config('agent-mcp.abilities.<key>'). Returning the key keeps subclasses free of
 * the literal ability string and routes every tool through one config surface.
 */
abstract class AbstractAgentTool extends Tool
{
    public function __construct(
        protected readonly ReadonlyConnectionResolver $connectionResolver,
        protected readonly OutputRedactor $outputRedactor,
        protected readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Return the config ability key this tool requires (e.g. 'read', 'artisan').
     * The base maps it to the concrete ability string via config.
     */
    abstract protected function requiredAbility(): string;

    /**
     * Authoritative authorization gate. MUST be called at the top of every
     * subclass handle(); returns a JSON-RPC denial Response when access is
     * refused, or null when the call may proceed.
     *
     * Denial conditions (fail closed):
     *   1. The tool is disabled in config('agent-mcp.tools.<name>').
     *   2. The configured authorizer (AuthorizesAgentTools) denies. It owns the full
     *      access decision (caller presence + ability); the default Sanctum authorizer
     *      fails closed on a null user, an empty ability, a non-token principal, or a
     *      session TransientToken. A host that authenticates differently (Passport
     *      scopes, request-attribute envelopes) binds its own authorizer via config.
     *
     * handle() re-checks here rather than trusting the route middleware: authorization
     * is enforced at the tool regardless of transport. Denials are intentionally
     * generic, never echoing the ability string or config key, to avoid leaking the
     * authorization surface to the agent.
     */
    protected function authorize(): ?Response
    {
        if (! $this->toolEnabled()) {
            return Response::error('This tool is disabled.');
        }

        // The configured authorizer owns the FULL access decision (caller presence +
        // ability). It receives the guard user as a hint, but a host whose principal
        // lives elsewhere (request attributes, a custom token envelope) can ignore it
        // and read its own context via request(). It fails closed: the default denies a
        // null user, an empty ability, or a credential it cannot confirm, so a missing
        // ability key or a session credential can never widen access.
        if (! $this->authorizer()->authorizes($this->user(), $this->resolvedAbility())) {
            return Response::error('This action is unauthorized.');
        }

        return null;
    }

    /**
     * Best-effort registration visibility (NOT a security boundary). Hides the tool
     * when it is disabled in config, or when a user is reachable at registration time
     * and the configured authorizer denies the ability. When no user is reachable, the
     * tool stays visible and handle() remains the authoritative gate.
     */
    public function shouldRegister(): bool
    {
        if (! $this->toolEnabled()) {
            return false;
        }

        $user = $this->user();

        if ($user === null) {
            return true;
        }

        return $this->authorizer()->authorizes($user, $this->resolvedAbility());
    }

    /**
     * Record this invocation through the audit pipeline, passing the resolved
     * principal so the logger can attach user / token identity.
     *
     * @param  array<string, string>  $argShape  Argument shape map (key => type),
     *                                           never raw values; derive it with
     *                                           argumentShape().
     */
    protected function audit(array $argShape): void
    {
        $this->auditLogger->record($this->name(), $argShape, $this->user());
    }

    /**
     * Best-effort output redactor for tool responses.
     */
    protected function redactor(): OutputRedactor
    {
        return $this->outputRedactor;
    }

    /**
     * The hardened read-only database connection. All DB access in subclasses
     * goes through this; it is the package's real SQL-injection boundary.
     */
    protected function readonly(): Connection
    {
        return $this->connectionResolver->connection();
    }

    /**
     * The configured ability authorizer. The service provider binds it from
     * config('agent-mcp.authorizer') (default: SanctumTokenAuthorizer); resolving it
     * per call lets a host swap the binding without reconstructing the tool.
     */
    protected function authorizer(): AuthorizesAgentTools
    {
        return app(AuthorizesAgentTools::class);
    }

    /**
     * Derive the audit-safe shape of a tool's arguments: each key mapped to its
     * value type, never the value itself, so the audit trail records "what kind"
     * without recording "what".
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, string>
     */
    protected function argumentShape(array $arguments): array
    {
        $shape = [];

        foreach ($arguments as $key => $value) {
            $shape[$key] = match (true) {
                is_string($value) => 'string',
                is_int($value) => 'integer',
                is_float($value) => 'float',
                is_bool($value) => 'boolean',
                is_array($value) => 'array',
                is_null($value) => 'null',
                default => get_debug_type($value),
            };
        }

        return $shape;
    }

    /**
     * Resolve the concrete ability string from the subclass's config key.
     */
    private function resolvedAbility(): string
    {
        $key = $this->requiredAbility();
        $ability = config("agent-mcp.abilities.{$key}");

        return is_string($ability) ? $ability : '';
    }

    /**
     * Whether this tool is enabled in config('agent-mcp.tools.<name>').
     */
    private function toolEnabled(): bool
    {
        return (bool) config("agent-mcp.tools.{$this->name()}", false);
    }

    /**
     * Resolve the authenticated principal from the route's auth guard. This is
     * the single source of identity for the security model: independent of the
     * mcp request object, it reads whatever the auth:sanctum middleware set.
     */
    private function user(): ?Authenticatable
    {
        return auth()->guard()->user();
    }
}
