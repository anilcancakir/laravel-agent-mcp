<?php

declare(strict_types=1);

namespace Anilcancakir\LaravelAgentMcp\Commands;

use Illuminate\Console\Command;

/**
 * One-shot setup command for laravel-agent-mcp.
 *
 * Publishes the package config and agent assets (AGENTS.md, .mcp.json.example),
 * then prints four guidance sections so the adopter can connect a client securely
 * without reading the README first:
 *   1. Per-engine readonly DB user provisioning reminder.
 *   2. Sanctum token creation snippet (no real token is ever printed or generated).
 *   3. Ready-to-paste HTTP and stdio client config blocks.
 *   4. Security warning: never expose the endpoint with app.debug=true.
 */
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'agent-mcp:install';

    /** @var string */
    protected $description = 'Publish the agent-mcp config and assets, then print client setup instructions.';

    public function handle(): int
    {
        // 1. Publish config and agent assets so the adopter has the files on disk.
        $this->call('vendor:publish', ['--tag' => 'agent-mcp-config']);
        $this->call('vendor:publish', ['--tag' => 'agent-mcp-assets']);

        $this->newLine();

        // 2. Print the four guidance sections.
        $this->printDbUserReminder();
        $this->printTokenCreationSnippet();
        $this->printClientConfigBlocks();
        $this->printDebugWarning();

        return self::SUCCESS;
    }

    /**
     * Print the per-engine readonly DB user provisioning reminder.
     *
     * The package enforces what it can at the connection layer (PRAGMA query_only
     * for SQLite, statement timeouts for MySQL/PostgreSQL), but the DB grant must
     * be set up by the adopter before the tools can be used safely.
     */
    private function printDbUserReminder(): void
    {
        $this->info('=== Readonly DB user setup ===');
        $this->newLine();
        $this->line('Create a dedicated read-only database user and set the agent-mcp.connection config key to it.');
        $this->newLine();
        $this->line('MySQL:');
        $this->line('  GRANT SELECT ON your_db.* TO \'agent\'@\'localhost\' IDENTIFIED BY \'...\';');
        $this->line('  -- Do NOT grant FILE, SUPER, or any write privilege.');
        $this->line('  -- Ensure secure_file_priv is set on the server.');
        $this->newLine();
        $this->line('PostgreSQL:');
        $this->line('  CREATE ROLE agent_readonly LOGIN PASSWORD \'...\';');
        $this->line('  GRANT SELECT ON ALL TABLES IN SCHEMA public TO agent_readonly;');
        $this->line('  -- Do NOT add the role to pg_read_server_files.');
        $this->line('  -- Do NOT grant COPY or lo_* privileges.');
        $this->newLine();
        $this->line('SQLite:');
        $this->line('  Open the database file read-only (mode=ro in the DSN).');
        $this->line('  The package also sets PRAGMA query_only = ON at connection time.');
        $this->newLine();
    }

    /**
     * Print the Sanctum token creation snippet.
     *
     * No real token is generated here; the adopter runs this against their own
     * user model after adding the HasApiTokens trait.
     */
    private function printTokenCreationSnippet(): void
    {
        $this->info('=== Sanctum token ===');
        $this->newLine();
        $this->line('Create a Sanctum personal access token with the agent-mcp:read ability:');
        $this->newLine();
        $this->line('  $token = $user->createToken(\'agent-mcp\', [\'agent-mcp:read\']);');
        $this->line('  echo $token->plainTextToken;');
        $this->newLine();
        $this->line('Add agent-mcp:artisan to the abilities array only when you enable run_artisan.');
        $this->newLine();
    }

    /**
     * Print the ready-to-paste HTTP and stdio client config blocks.
     *
     * The HTTP URL is built from config('app.url') and config('agent-mcp.route')
     * so the printed block matches the actual endpoint the adopter just registered.
     */
    private function printClientConfigBlocks(): void
    {
        $appUrl = rtrim((string) config('app.url', 'https://your-app.test'), '/');
        $route = ltrim((string) config('agent-mcp.route', 'mcp'), '/');
        $httpUrl = $appUrl.'/'.$route;

        $this->info('=== Client config (.mcp.json) ===');
        $this->newLine();
        $this->line('HTTP transport (Streamable HTTP, recommended for remote and production use):');
        $this->newLine();
        $this->line(json_encode([
            'mcpServers' => [
                'agent-mcp-http' => [
                    'type' => 'http',
                    'url' => $httpUrl,
                    'headers' => [
                        'Authorization' => 'Bearer <your-token>',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->line('stdio transport (local artisan process, no network hop):');
        $this->newLine();
        $this->line(json_encode([
            'mcpServers' => [
                'agent-mcp-stdio' => [
                    'type' => 'stdio',
                    'command' => 'php',
                    'args' => ['artisan', 'mcp:start', 'agent-mcp'],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->line('Note: Claude Desktop does not natively support custom HTTP headers.');
        $this->line('Use the mcp-remote shim: https://github.com/geelen/mcp-remote');
        $this->newLine();
    }

    /**
     * Print the app.debug=true security warning (Oracle IMP6).
     *
     * The package strips stack traces from MCP error responses regardless of the
     * debug flag, but the endpoint should never be reachable in a debug-mode
     * environment because other Laravel error pages and debug bars may still leak.
     */
    private function printDebugWarning(): void
    {
        $this->warn('=== Security warning ===');
        $this->newLine();
        $this->warn('NEVER expose the MCP endpoint when app.debug=true.');
        $this->line('With debug enabled, Laravel error pages and debug toolbars can leak');
        $this->line('configuration values, stack traces, and environment variables to any');
        $this->line('client that can reach the endpoint, including the connected LLM agent.');
        $this->newLine();
        $this->line('Set APP_DEBUG=false in production, or restrict the endpoint to an');
        $this->line('internal network before turning debug mode on.');
        $this->newLine();
    }
}
