<?php

use Anilcancakir\LaravelAgentMcp\Server\AgentMcpServer;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Absolute path to the repository root's server.json.
 */
function serverManifestPath(): string
{
    return dirname(__DIR__, 2).'/server.json';
}

/**
 * Decode server.json into an associative array.
 *
 * @return array<string, mixed>
 */
function serverManifest(): array
{
    return json_decode((string) File::get(serverManifestPath()), true);
}

describe('server.json registry manifest', function (): void {

    it('exists at the repository root', function (): void {
        expect(File::exists(serverManifestPath()))->toBeTrue();
    });

    it('omits both packages and remotes', function (): void {
        $manifest = serverManifest();

        expect($manifest)->not->toHaveKey('packages');
        expect($manifest)->not->toHaveKey('remotes');
    });

    it('keeps its description within the registry schema limit', function (): void {
        expect(mb_strlen(serverManifest()['description']))->toBeLessThanOrEqual(100);
    });

    it('does not carry the inaccurate "no write access" claim', function (): void {
        expect(serverManifest()['description'])->not->toContain('no write access');
    });

    it('stays in sync with the AgentMcpServer Version attribute', function (): void {
        $reflection = new ReflectionClass(AgentMcpServer::class);
        $attribute = $reflection->getAttributes(Version::class)[0]->newInstance();

        expect(serverManifest()['version'])->toBe($attribute->value);
    });

    it('uses the io.github.anilcancakir namespace', function (): void {
        expect(serverManifest()['name'])->toStartWith('io.github.anilcancakir/');
    });

});
