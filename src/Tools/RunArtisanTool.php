<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Runs an allowlisted artisan command as the host application.
 *
 * Executing artisan as the app is a confused-deputy surface (Oracle IMP5): a
 * benign-looking command name can still accept destructive options (e.g.
 * --force on a migration). The allowlist is therefore the WHOLE authorization
 * for WHICH command runs, and it authorizes options explicitly, not just names:
 *
 *   - EXACT command match only. No substring, prefix, or wildcard matching: the
 *     submitted command must equal an allowlist entry's command name verbatim.
 *   - Default-deny on options. A bare-string entry ('route:list') permits the
 *     command with NO options. An array entry
 *     (['command' => 'cache:clear', 'options' => ['--force']]) permits only the
 *     options it lists. Any submitted argument or option not on that list is
 *     rejected before the command is dispatched.
 *
 * An empty allowlist (the default) denies authoritatively in handle(); the tool
 * is also hidden via shouldRegister(), but that is best-effort UX only: the deny
 * happens regardless of registration (Oracle IMP5 — hiding is not authorization).
 */
class RunArtisanTool extends AbstractAgentTool
{
    protected string $name = 'run_artisan';

    protected function requiredAbility(): string
    {
        return 'artisan';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()
                ->description('The exact artisan command name to run. Must be allowlisted.')
                ->required(),
            'arguments' => $schema->array()
                ->description('Optional map of argument/option name to value. Only options the allowlist permits are accepted.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative ability + tool-enabled gate (Oracle IMP5: handle() is the boundary).
        if ($denial = $this->authorize()) {
            return $denial;
        }

        $this->audit($this->argumentShape($request->all()));

        $command = (string) $request->get('command', '');
        $arguments = $request->get('arguments', []);
        $arguments = is_array($arguments) ? $arguments : [];

        // 2. The allowlist is the whole command authorization: resolve the exact-match entry.
        $entry = $this->allowlistEntryFor($command);

        if ($entry === null) {
            return Response::error('This command is not permitted.');
        }

        // 3. Default-deny on options: reject anything the entry does not explicitly permit.
        if (! $this->argumentsArePermitted($arguments, $entry['options'])) {
            return Response::error('One or more arguments are not permitted for this command.');
        }

        // 4. Dispatch with bound parameters, then redact the captured output.
        Artisan::call($command, $arguments);

        return Response::text($this->redactor()->redact(Artisan::output()));
    }

    /**
     * Whether the tool should be offered for registration. Best-effort UX only:
     * an empty allowlist (or one with no usable entries) leaves nothing to run,
     * so the tool is hidden. Security never depends on this; handle() denies an
     * empty allowlist regardless.
     */
    public function shouldRegister(): bool
    {
        return parent::shouldRegister() && $this->normalizedAllowlist() !== [];
    }

    /**
     * Resolve the allowlist entry whose command EXACT-matches the submitted name.
     *
     * @return array{command: string, options: array<int, string>}|null
     */
    private function allowlistEntryFor(string $command): ?array
    {
        if ($command === '') {
            return null;
        }

        foreach ($this->normalizedAllowlist() as $entry) {
            if ($entry['command'] === $command) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Whether every submitted argument is an option explicitly permitted by the
     * entry. Positional arguments are never declared by an allowlist entry, so
     * any non-option key is rejected. Default-deny: an empty options list rejects
     * every submitted argument.
     *
     * @param  array<array-key, mixed>  $arguments
     * @param  array<int, string>  $permittedOptions
     */
    private function argumentsArePermitted(array $arguments, array $permittedOptions): bool
    {
        foreach (array_keys($arguments) as $key) {
            $name = (string) $key;

            // Only declared options pass; positional arguments and unlisted options are denied.
            if (! str_starts_with($name, '--') || ! in_array($name, $permittedOptions, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize the configured allowlist into a uniform shape, dropping malformed
     * entries (defensive: a misconfigured entry must never widen authorization).
     *
     * @return array<int, array{command: string, options: array<int, string>}>
     */
    private function normalizedAllowlist(): array
    {
        $allowlist = config('agent-mcp.artisan.allowlist', []);

        if (! is_array($allowlist)) {
            return [];
        }

        $normalized = [];

        foreach ($allowlist as $entry) {
            // Bare string: command name with no permitted options.
            if (is_string($entry) && $entry !== '') {
                $normalized[] = [
                    'command' => $entry,
                    'options' => [],
                ];

                continue;
            }

            // Array shape: ['command' => string, 'options' => string[]].
            if (is_array($entry) && isset($entry['command']) && is_string($entry['command']) && $entry['command'] !== '') {
                $options = $entry['options'] ?? [];
                $normalized[] = [
                    'command' => $entry['command'],
                    'options' => is_array($options) ? array_values(array_filter($options, 'is_string')) : [],
                ];
            }
        }

        return $normalized;
    }
}
