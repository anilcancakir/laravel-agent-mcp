<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool: config_inspect
 *
 * Inspects the resolved application config tree. This is the highest
 * secret-exposure surface in the suite, so it is value-free by default and
 * gates every value reveal behind an explicit, layered opt-in.
 *
 * Default response (no reveal_values): the dot-path of every leaf under the
 * requested key plus its gettype, NEVER its value. This lets an agent discover
 * the config structure without reading a single secret.
 *
 * A leaf value is returned ONLY when ALL of these hold, evaluated in this order
 * (gating FIRST, OutputRedactor as the last net only, per Oracle value-gating
 * order):
 *
 *   1. reveal_values=true is explicitly requested.
 *   2. The full dot-path is in the union of config('agent-mcp.config_inspect.safe_list')
 *      and the call's safe_keys argument (operator + caller opt-in).
 *   3. The full dot-path is NOT matched by the block-list (case-insensitive
 *      substring of any block_list token in the path). The block-list WINS over
 *      the safe-list: a DSN/url path stays redacted even when safe-listed,
 *      because connection strings embed user:pass@host credentials.
 *
 * Any failed gate yields [REDACTED] in place of the value. The whole payload is
 * then passed through OutputRedactor as a final, best-effort net.
 */
#[Name('config_inspect')]
class ConfigInspectTool extends AbstractAgentTool
{
    /**
     * The marker rendered in place of a withheld leaf value.
     */
    private const REDACTED = '[REDACTED]';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()
                ->description('Config dot-path or file to inspect, e.g. "app" or "database.connections.mysql".'),
            'reveal_values' => $schema->boolean()
                ->nullable()
                ->description('Request leaf values. A value is returned only for a safe-listed, non-block-listed path; otherwise [REDACTED].'),
            'safe_keys' => $schema->array()
                ->items($schema->string())
                ->nullable()
                ->description('Explicit dot-paths to reveal, merged with the operator safe_list. Block-listed paths stay redacted regardless.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit the invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. Resolve the requested config key.
        $key = (string) $request->get('key');

        if ($key === '') {
            return Response::error('A non-empty config key is required.');
        }

        if (! config()->has($key)) {
            return Response::error("Unknown config key: {$key}");
        }

        $value = config($key);
        $revealValues = (bool) $request->get('reveal_values', false);
        $safeKeys = $this->safeKeys($request->get('safe_keys'));

        // 4. Flatten the subtree into dot-path leaves; a scalar config value is a
        //    single leaf keyed by the requested path itself.
        $leaves = is_array($value)
            ? Arr::dot($value, $key.'.')
            : [$key => $value];

        $children = [];

        foreach ($leaves as $path => $leafValue) {
            $children[] = [
                'path' => $path,
                'type' => gettype($leafValue),
                'value' => $this->gatedValue((string) $path, $leafValue, $revealValues, $safeKeys),
            ];
        }

        $payload = [
            'key' => $key,
            'type' => gettype($value),
            'children' => $children,
        ];

        // Redaction is the last net only; the path gate above is the real guard.
        $redacted = $this->redactor()->redactArray($payload);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * Apply the value gate to a single leaf. Returns the value ONLY when reveal
     * was requested AND the path is safe-listed AND the path is not block-listed.
     * Gating happens here, FIRST; OutputRedactor is the last net.
     *
     * @param  array<int, string>  $safeKeys
     */
    private function gatedValue(string $path, mixed $value, bool $revealValues, array $safeKeys): mixed
    {
        if (! $revealValues) {
            return self::REDACTED;
        }

        if (! in_array($path, $safeKeys, true)) {
            return self::REDACTED;
        }

        // Block-list wins over the safe-list: a credential-bearing path (url/dsn/
        // secret/...) is never revealed even when the caller safe-listed it.
        if ($this->pathIsBlockListed($path)) {
            return self::REDACTED;
        }

        return $value;
    }

    /**
     * The union of the operator safe_list and the call's safe_keys argument.
     *
     * @return array<int, string>
     */
    private function safeKeys(mixed $callerSafeKeys): array
    {
        $operatorSafeList = config('agent-mcp.config_inspect.safe_list', []);
        $operatorSafeList = is_array($operatorSafeList) ? $operatorSafeList : [];

        $callerSafeKeys = is_array($callerSafeKeys) ? $callerSafeKeys : [];

        $merged = array_merge($operatorSafeList, $callerSafeKeys);

        // Keep string entries only; a non-string safe-key can never match a path.
        return array_values(array_filter($merged, 'is_string'));
    }

    /**
     * Whether the dot-path matches a block-list token via case-insensitive
     * substring. Mirrors the cache_inspect block-list matching for consistency.
     */
    private function pathIsBlockListed(string $path): bool
    {
        $blockList = config('agent-mcp.config_inspect.block_list', []);

        if (! is_array($blockList)) {
            return false;
        }

        $haystack = strtolower($path);

        foreach ($blockList as $token) {
            if (is_string($token) && $token !== '' && str_contains($haystack, strtolower($token))) {
                return true;
            }
        }

        return false;
    }
}
