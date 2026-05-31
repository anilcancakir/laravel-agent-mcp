<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool: env_keys
 *
 * Lists the NAMES of the process environment variables, sorted, and NEVER their
 * values. An env value is the highest-density secret surface in a Laravel app
 * (APP_KEY, DB_PASSWORD, third-party API keys), so this tool reads array_keys()
 * only: the values never enter the payload at any point.
 *
 * The result reflects the live process environment ($_ENV), which on a typical
 * deployment is the set populated from the .env file plus the OS environment.
 * It is a discovery aid (what configuration knobs exist), not a value reader.
 */
#[Name('env_keys')]
class EnvKeysTool extends AbstractAgentTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate.
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit the invocation shape (keys + types, never values).
        $this->audit($this->argumentShape($request->all()));

        // 3. Collect NAMES only. Values are deliberately never read: array_keys()
        //    drops them at the source so there is no value to leak downstream.
        $keys = array_keys($_ENV);
        sort($keys);

        $payload = [
            'note' => 'Reflects the live process environment ($_ENV). Names only; values are never read. On a deployment with cached config (php artisan config:cache) the framework skips loading the .env file, so $_ENV may contain only OS-level variables and not the .env keys.',
            'count' => count($keys),
            'keys' => $keys,
        ];

        // Redaction is the last net only; the value-free design is the real guard.
        $redacted = $this->redactor()->redactArray($payload);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
