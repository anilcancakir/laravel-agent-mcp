<?php

namespace Anilcancakir\LaravelAgentMcp\Commands;

use Illuminate\Console\Command;

/**
 * One-shot setup command for laravel-agent-mcp.
 *
 * Publishes the package config and agent assets (AGENTS.md, .mcp.json.example),
 * then prints six guidance sections so the adopter can connect a client securely
 * without reading the README first:
 *   1. AGENT_MCP_KEY generation and mandatory env setup.
 *   2. Per-engine readonly DB user provisioning reminder.
 *   3. HTTP client .mcp.json block (Streamable HTTP with Bearer auth).
 *   4. stdio bridge .mcp.json block (agent-mcp:stdio command with AGENT_MCP_URL + AGENT_MCP_KEY).
 *   5. claude mcp add one-liner for the HTTP endpoint.
 *   6. Security warning: never expose the endpoint with app.debug=true.
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

        // 2. Print the six guidance sections.
        $this->printKeySetupInstructions();
        $this->printDbUserReminder();
        $this->printClientConfigBlocks();
        $this->printClaudeMcpAddOneliner();
        $this->printDebugWarning();

        return self::SUCCESS;
    }

    /**
     * Print the AGENT_MCP_KEY generation hint and mandatory env setup instructions.
     *
     * The server returns 401 for every request until this key is set. A strong
     * random key is required; the printed command generates a suitable one.
     */
    private function printKeySetupInstructions(): void
    {
        $this->info('=== Server key setup (MANDATORY) ===');
        $this->newLine();
        $this->line('The MCP endpoint requires a server key. Every request without a valid');
        $this->line('Authorization: Bearer <key> header is rejected with 401.');
        $this->newLine();
        $this->line('Generate a strong key:');
        $this->line('  php -r "echo bin2hex(random_bytes(32));"');
        $this->newLine();
        $this->line('Add it to .env:');
        $this->line('  AGENT_MCP_KEY=paste-generated-key-here');
        $this->newLine();
        $this->line('The key is read from config(\'agent-mcp.key\'). The server is CLOSED');
        $this->line('(returns 401) until this value is set to a non-empty string.');
        $this->newLine();
    }

    /**
     * Print the per-engine readonly DB user provisioning reminder.
     *
     * The package enforces read-only access at the code layer (SELECT validator,
     * per-engine read-only session flags). A dedicated readonly DB user is still
     * strongly recommended as an additional defense layer, especially on MySQL
     * where no per-session read-only mode exists for a normal user.
     */
    private function printDbUserReminder(): void
    {
        $this->info('=== Readonly DB user setup (recommended) ===');
        $this->newLine();
        $this->line('The package enforces read-only access in code (SELECT validator, session flags).');
        $this->line('It works on the default connection without a dedicated user, but a readonly');
        $this->line('DB user is strongly recommended as an additional defense layer.');
        $this->newLine();
        $this->line('MySQL (IMPORTANT: MySQL has no per-session read-only for a normal user;');
        $this->line('the code layer is the primary write boundary on MySQL):');
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
        $this->line('Point agent-mcp.connection to the readonly user\'s connection name in config/agent-mcp.php.');
        $this->line('Leave it null to use the default connection (code-enforced read-only, readonly user recommended).');
        $this->newLine();
    }

    /**
     * Print the ready-to-paste HTTP and stdio bridge client config blocks.
     *
     * The HTTP block uses Streamable HTTP with a Bearer key header. The stdio
     * bridge block runs the agent-mcp:stdio command locally; it connects to the
     * remote endpoint using AGENT_MCP_URL and AGENT_MCP_KEY from the env block.
     */
    private function printClientConfigBlocks(): void
    {
        $appUrl = rtrim((string) config('app.url', 'https://your-app.test'), '/');
        $route = ltrim((string) config('agent-mcp.route', 'agent-mcp'), '/');
        $httpUrl = $appUrl.'/'.$route;

        $this->info('=== Client config (.mcp.json) ===');
        $this->newLine();
        $this->line('HTTP transport (Streamable HTTP, recommended for remote and production use):');
        $this->newLine();
        $this->line(json_encode([
            'mcpServers' => [
                'agent-mcp' => [
                    'type' => 'http',
                    'url' => $httpUrl,
                    'headers' => [
                        'Authorization' => 'Bearer YOUR_KEY_HERE',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->line('stdio bridge (local artisan command, forwards requests to the remote endpoint):');
        $this->line('Set AGENT_MCP_URL and AGENT_MCP_KEY in the env block (or in your shell env):');
        $this->newLine();
        $this->line(json_encode([
            'mcpServers' => [
                'agent-mcp' => [
                    'type' => 'stdio',
                    'command' => 'php',
                    'args' => [
                        'artisan',
                        'agent-mcp:stdio',
                    ],
                    'env' => [
                        'AGENT_MCP_URL' => 'https://your-remote-app.test/agent-mcp',
                        'AGENT_MCP_KEY' => 'YOUR_KEY_HERE',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
    }

    /**
     * Print the claude mcp add one-liner for the HTTP endpoint.
     *
     * Laravel Boost cannot auto-wire third-party MCP servers; the adopter must
     * run this command manually to register the server with the Claude CLI.
     */
    private function printClaudeMcpAddOneliner(): void
    {
        $appUrl = rtrim((string) config('app.url', 'https://your-app.test'), '/');
        $route = ltrim((string) config('agent-mcp.route', 'agent-mcp'), '/');
        $httpUrl = $appUrl.'/'.$route;

        $this->info('=== Register with Claude CLI ===');
        $this->newLine();
        $this->line('Laravel Boost does not auto-wire third-party MCP servers (laravel/boost#522).');
        $this->line('Register the endpoint manually with the Claude CLI:');
        $this->newLine();
        $this->line('  claude mcp add --transport http '.$httpUrl.' --header "Authorization: Bearer YOUR_KEY_HERE"');
        $this->newLine();
        $this->line('Or for the stdio bridge:');
        $this->newLine();
        $this->line('  claude mcp add --transport stdio php -- artisan agent-mcp:stdio');
        $this->newLine();
    }

    /**
     * Print the app.debug=true security warning.
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
