<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Anilcancakir\LaravelAgentMcp\Auditing\AuditLogger;
use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Anilcancakir\LaravelAgentMcp\Support\OutputRedactor;
use Illuminate\Database\Connection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Shared base for every agent MCP tool: the tool-enabled gate plus the common
 * readonly / redaction / audit pipeline every concrete tool inherits.
 *
 * Authentication is the HTTP layer's job (KeyAuthMiddleware enforces the single
 * server-admin key, fail-closed, before the MCP route is reached). The tool no
 * longer carries any per-caller ability model: there is one key, and holding it
 * grants the full read surface. The only access decision left at the tool is the
 * per-tool enable flag, which lets an operator switch individual tools off.
 *
 * Verified against the INSTALLED laravel/mcp source (0.6 through 1.x, the CI matrix), since
 * docs and source disagree on these details (Research Summary CAUTION):
 *   - Tool::handle is NOT declared on the base; it is invoked by CallTool via
 *     Container::call([$tool, 'handle']) (method injection). Subclasses declare
 *     their own handle(Request $request): Response and resolve extra dependencies
 *     by type-hint. The container also resolves the tool instance itself
 *     (ServerContext::resolvePrimitives + PendingTestResponse::resolvePrimitive),
 *     so CONSTRUCTOR injection of the three collaborators works.
 *   - shouldRegister is NOT declared on the base; Primitive::eligibleForRegistration
 *     calls it via Container::call when method_exists, so its arguments are
 *     method-injected and its presence is optional.
 *
 * Security model (hiding is not authorization):
 *   - authorize() is the AUTHORITATIVE tool-enabled check. Every subclass handle()
 *     calls it FIRST and returns its denial Response when non-null, before doing any
 *     work. The route middleware has already proven the caller holds the key.
 *   - shouldRegister() is best-effort UX only: it hides a disabled tool from the
 *     tool list. Security never depends on it; an always-registered tool is still
 *     denied by handle() when disabled.
 */
abstract class AbstractAgentTool extends Tool
{
    public function __construct(
        protected readonly ReadonlyConnectionResolver $connectionResolver,
        protected readonly OutputRedactor $outputRedactor,
        protected readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Authoritative authorization gate. MUST be called at the top of every
     * subclass handle(); returns a JSON-RPC denial Response when the tool is
     * disabled, or null when the call may proceed.
     *
     * The HTTP middleware (KeyAuthMiddleware) is the whole auth boundary: by the
     * time a tool runs, the server-admin key has already been verified. The only
     * decision left here is whether this specific tool is enabled in config. The
     * denial is intentionally generic, never echoing the config key, so it cannot
     * leak the configuration surface to the agent. Each subclass audits its own
     * invocation shape immediately after this gate returns null.
     */
    protected function authorize(): ?Response
    {
        if (! $this->toolEnabled()) {
            return Response::error('This tool is disabled.');
        }

        return null;
    }

    /**
     * Best-effort registration visibility (NOT a security boundary). Hides the tool
     * when it is disabled in config; handle() remains the authoritative gate.
     */
    public function shouldRegister(): bool
    {
        return $this->toolEnabled();
    }

    /**
     * Record this invocation through the audit pipeline.
     *
     * @param  array<string, string>  $argShape  Argument shape map (key => type),
     *                                           never raw values; derive it with
     *                                           argumentShape().
     * @param  string|null  $section  The section a merged tool resolved, from requestedSection().
     */
    protected function audit(array $argShape, ?string $section = null): void
    {
        $this->auditLogger->record($this->name(), $argShape, $section);
    }

    /**
     * Resolve the section a merged tool should run. laravel/mcp neither enforces a
     * schema enum nor applies its default, so the handler validates here: a missing
     * section falls back to the first (default) one, anything else must be listed.
     * The message names the valid values and never echoes the submitted one.
     *
     * @param  non-empty-array<int, string>  $sections  Valid names, default first.
     */
    protected function requestedSection(Request $request, array $sections): string
    {
        $section = $request->get('section');

        if ($section === null) {
            return $sections[0];
        }

        $message = 'The section must be one of: '.implode(', ', $sections).'.';

        try {
            $validated = $request->validate(
                ['section' => ['required', 'string', Rule::in($sections)]],
                [
                    'section.required' => $message,
                    'section.string' => $message,
                    'section.in' => $message,
                ],
            );
        } catch (ValidationException $exception) {
            // A refused section is still an invocation: record its argument shape (never the
            // refused value, and no section) before the refusal reaches the caller.
            $this->audit($this->argumentShape($request->all()));

            throw $exception;
        }

        return $validated['section'];
    }

    /**
     * Redact then encode a payload as compact JSON, the single output shape of the
     * JSON tools: no pretty-printing (whitespace costs agent tokens), slashes and
     * unicode left readable.
     *
     * A value json_encode cannot represent (a non-finite float from opcache_get_status()
     * under JIT, invalid UTF-8 in a binary column) must not sink the rest of the payload:
     * the partial-output flag turns the float into 0 (PHP's own substitute) and the substitute flag replaces
     * the bad bytes. PHP lets partial output win over throw, so only a failure it cannot
     * repair (depth, recursion) still throws instead of returning '{}'.
     *
     * @param  array<mixed>  $payload
     */
    protected function jsonResponse(array $payload): Response
    {
        $redacted = $this->redactor()->redactArray($payload);

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

        return Response::text(json_encode($redacted, $flags));
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
     * Whether this tool is enabled in config('agent-mcp.tools.<name>').
     */
    private function toolEnabled(): bool
    {
        return (bool) config("agent-mcp.tools.{$this->name()}", false);
    }
}
