<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * MCP tool: storage_info
 *
 * Reports the configured filesystem disks and the public storage symlink map,
 * without ever emitting disk credentials. Cloud disks (S3, etc.) carry access
 * keys and secrets in their config block; this tool strips the credential keys
 * (key/secret/password/token) before the disk config is reported, keeping only
 * the structural attributes (driver, root, url, visibility, region, bucket).
 *
 * For each configured storage link the tool reports whether the link path is a
 * symlink, where it points (readlink), and whether the target exists, so an
 * agent can diagnose a missing or broken `storage:link` without filesystem
 * write access.
 */
#[Name('storage_info')]
#[Description(<<<'TEXT'
    Report the configured filesystem disks and public storage links. Use it when inspecting disk configuration or symlink state.

    Usage:
    - Takes no arguments.
    - Disk credentials (key, secret, password, token) are stripped from the output; root paths are reported as-is. Symlinks include a liveness check.
    - Read-only.
    TEXT)]
class StorageInfoTool extends AbstractAgentTool
{
    /**
     * Credential keys stripped from every disk config before it is reported. A
     * cloud disk embeds its access credentials under these keys; they are never
     * structural information an agent needs, so they are dropped at the source.
     *
     * @var array<int, string>
     */
    private const CREDENTIAL_KEYS = [
        'key',
        'secret',
        'password',
        'token',
    ];

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

        // 3. Build the credential-stripped disk map and the resolved link map.
        $payload = [
            'default' => config('filesystems.default'),
            'disks' => $this->disks(),
            'links' => $this->links(),
        ];

        // Redaction is the last net only; the per-disk credential strip is the real guard.
        $redacted = $this->redactor()->redactArray($payload);

        return Response::text(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * The configured disks with their credential keys stripped.
     *
     * @return array<string, array<string, mixed>>
     */
    private function disks(): array
    {
        $disks = config('filesystems.disks', []);

        if (! is_array($disks)) {
            return [];
        }

        $result = [];

        foreach ($disks as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            $result[$name] = $this->stripCredentials($config);
        }

        return $result;
    }

    /**
     * Remove the credential keys from a single disk config, preserving every
     * other (structural) attribute.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function stripCredentials(array $config): array
    {
        foreach (self::CREDENTIAL_KEYS as $credentialKey) {
            unset($config[$credentialKey]);
        }

        return $config;
    }

    /**
     * The configured public storage links, each annotated with its current
     * filesystem state (is_link / readlink / target exists). Reads only; never
     * creates or removes a link.
     *
     * @return array<int, array<string, mixed>>
     */
    private function links(): array
    {
        $links = config('filesystems.links', []);

        if (! is_array($links)) {
            return [];
        }

        $result = [];

        foreach ($links as $link => $target) {
            $linkPath = (string) $link;
            $isLink = is_link($linkPath);

            $result[] = [
                'link' => $linkPath,
                'target' => (string) $target,
                'is_link' => $isLink,
                'points_to' => $isLink ? (readlink($linkPath) ?: null) : null,
                'target_exists' => file_exists((string) $target),
            ];
        }

        return $result;
    }
}
