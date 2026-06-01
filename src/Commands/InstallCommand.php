<?php

namespace Anilcancakir\LaravelAgentMcp\Commands;

use Anilcancakir\LaravelAgentMcp\Support\AgentTarget;
use Anilcancakir\LaravelAgentMcp\Support\GuidelineInjector;
use Anilcancakir\LaravelAgentMcp\Support\InstallMode;
use Anilcancakir\LaravelAgentMcp\Support\RemoteUrl;
use Anilcancakir\LaravelAgentMcp\Support\SkillInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

use function Laravel\Prompts\multiselect;

/**
 * Two-mode setup command for laravel-agent-mcp.
 *
 * Resolves an install mode (MCP default vs CLI), records it in a committed
 * .agent-mcp.json (so the team, CI, and laravel-boost all see one mode), then
 * publishes the package config + agent assets and prints mode-tailored guidance.
 *
 * Shared in BOTH modes:
 *   1. AGENT_MCP_KEY generation and mandatory env setup.
 *   2. Per-engine readonly DB user provisioning reminder.
 *   3. Security warning: never expose the endpoint with app.debug=true.
 *   4. The exact laravel-boost next-step so boost injects the active-mode assets.
 *
 * MCP mode additionally prints:
 *   - HTTP + stdio .mcp.json client blocks.
 *   - The claude mcp add one-liner.
 *
 * CLI mode instead prints:
 *   - An agent-mcp:call / agent-mcp:tools usage block (local + remote), and
 *     SKIPS the .mcp.json blocks / claude mcp add.
 */
class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'agent-mcp:install
        {--mode= : The install mode to record (mcp or cli)}
        {--url= : Remote agent-mcp endpoint URL to record in .agent-mcp.json for CLI mode (https, or http for loopback)}
        {--agents= : Comma-separated agent keys to target, or "all"}
        {--no-inject : Skip writing guideline/skill into agent files}
        {--inject : Inject even when laravel-boost is installed}';

    /** @var string */
    protected $description = 'Record the install mode, publish the agent-mcp config and assets, then print mode-tailored setup instructions.';

    public function handle(): int
    {
        // 1. Resolve and record the mode; an invalid --mode fails closed (non-zero).
        $mode = $this->resolveMode();

        if ($mode === null) {
            return self::FAILURE;
        }

        // 2. Resolve the url to commit; an invalid --url fails closed before writing.
        $url = $this->resolveUrl($mode);

        if ($url === false) {
            return self::FAILURE;
        }

        $this->recordMode($mode, $url);

        // 3. Publish config + agent assets so the adopter has the files on disk.
        //    The .mcp.json.example shipped under agent-mcp-assets is harmless in cli
        //    mode (only the printed guidance differs), so both modes publish both tags.
        $this->call('vendor:publish', ['--tag' => 'agent-mcp-config']);
        $this->call('vendor:publish', ['--tag' => 'agent-mcp-assets']);

        $this->newLine();

        // 4. Print the shared guidance, then the mode-tailored sections.
        $this->printKeySetupInstructions();
        $this->printDbUserReminder();

        if ($mode === 'mcp') {
            $this->printClientConfigBlocks();
            $this->printClaudeMcpAddOneliner();
        } else {
            $this->printCliUsageBlock();
        }

        $this->printDebugWarning();

        // 5. Inject the guideline + skill directly when boost is absent (or --inject),
        //    unless --no-inject. Otherwise defer to boost via the printed next-step.
        $shouldInject = (! $this->boostIsInstalled() || $this->option('inject'))
            && ! $this->option('no-inject');

        if (! $shouldInject) {
            if ($this->option('no-inject')) {
                $this->line('Skipped guideline/skill injection (--no-inject).');
                $this->newLine();
            }

            $this->printBoostNextStep($mode);

            return self::SUCCESS;
        }

        return $this->injectAgentAssets($mode);
    }

    /**
     * Render the active-mode guideline and inject it (plus the skill dir) into the
     * selected agents, boost-independently.
     *
     * Returns FAILURE when the agent selection is invalid or when the guideline
     * injector aborts on an unbalanced marker set; in the abort case the message is
     * surfaced to stderr and no partial run is reported as success.
     */
    private function injectAgentAssets(string $mode): int
    {
        // 1. Resolve which agents to target (flag / interactive multiselect / detected default).
        $targets = $this->resolveTargets();

        if ($targets === null) {
            return self::FAILURE;
        }

        // 2. Render the mode-correct guideline once; the blade reads the just-recorded mode.
        $guideline = trim(Blade::render(File::get(dirname(__DIR__).'/../resources/boost/guidelines/core.blade.php')));

        // 3. Dedupe shared guideline files (AGENTS.md) and skill dirs (.agents/skills) to unique paths.
        $guidelineFiles = $this->uniquePaths(array_map(
            fn (AgentTarget $target): string => base_path($target->guidelinePath),
            $targets,
        ));

        $skillDirs = $this->uniquePaths(array_map(
            fn (AgentTarget $target): string => base_path($target->skillPath),
            $targets,
        ));

        // 4. Inject the guideline first; abort loudly (FAILURE, no success summary) on unbalanced markers.
        try {
            $writtenGuidelines = (new GuidelineInjector)->inject($guidelineFiles, $guideline);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $writtenSkills = SkillInstaller::install($skillDirs, $mode);

        $this->printInjectionSummary($writtenGuidelines, $writtenSkills);

        return self::SUCCESS;
    }

    /**
     * Resolve the agent targets to write into.
     *
     * Precedence: --agents=all (every target), --agents=csv (validated keys),
     * an interactive multiselect (default = detected agents or Claude Code), and
     * finally a non-interactive default of the detected agents or Claude Code.
     * Returns null when --agents carries an unknown key (the error is printed).
     *
     * @return AgentTarget[]|null
     */
    private function resolveTargets(): ?array
    {
        $given = $this->option('agents');

        if ($given === 'all') {
            return AgentTarget::all();
        }

        if ($given !== null && $given !== '') {
            $keys = array_map(
                fn (string $key): string => strtolower(trim($key)),
                explode(',', $given),
            );

            try {
                return AgentTarget::fromKeys($keys);
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return null;
            }
        }

        if ($this->input->isInteractive()) {
            return $this->promptForTargets();
        }

        return $this->detectedTargetsOrDefault();
    }

    /**
     * Present an interactive multiselect of every target, pre-selecting the detected
     * agents (or Claude Code when none are detected), and map the chosen keys back to
     * AgentTarget instances.
     *
     * @return AgentTarget[]
     */
    private function promptForTargets(): array
    {
        $options = [];

        foreach (AgentTarget::all() as $target) {
            $options[$target->key] = $target->displayName;
        }

        $default = array_map(
            fn (AgentTarget $target): string => $target->key,
            $this->detectedTargetsOrDefault(),
        );

        /** @var array<int, string> $selected */
        $selected = multiselect(
            label: 'Which agents should receive the guideline and skill?',
            options: $options,
            default: $default,
        );

        return AgentTarget::fromKeys($selected);
    }

    /**
     * The auto-detected agent targets, falling back to Claude Code when detection
     * finds nothing, so a fresh project still receives the assets.
     *
     * @return AgentTarget[]
     */
    private function detectedTargetsOrDefault(): array
    {
        $detected = AgentTarget::detect();

        return $detected === [] ? AgentTarget::fromKeys(['claude_code']) : $detected;
    }

    /**
     * Collapse the given absolute paths to unique entries, preserving first-seen
     * order, so agents that share a guideline file or skill dir resolve once.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function uniquePaths(array $paths): array
    {
        return array_values(array_unique($paths));
    }

    /**
     * Print a concise summary of the guideline files and skill dirs written.
     *
     * @param  array<int, string>  $guidelineFiles
     * @param  array<int, string>  $skillDirs
     */
    private function printInjectionSummary(array $guidelineFiles, array $skillDirs): void
    {
        $this->info('=== Injected agent assets ===');
        $this->newLine();
        $this->line('Guideline block written to:');

        foreach ($guidelineFiles as $file) {
            $this->line('  '.$file);
        }

        $this->newLine();
        $this->line('Skill installed into:');

        foreach ($skillDirs as $dir) {
            $this->line('  '.$dir);
        }

        $this->newLine();
    }

    /**
     * Resolve the install mode from --mode, an interactive prompt, or the default.
     *
     * Returns the resolved mode string, or null when --mode carries an invalid
     * value (the caller maps null to a non-zero exit). A given --mode is validated
     * against InstallMode::modes(); without --mode an interactive run prompts
     * (default mcp) and a non-interactive run falls back to mcp.
     */
    private function resolveMode(): ?string
    {
        $given = $this->option('mode');

        if ($given !== null) {
            if (! in_array($given, InstallMode::modes(), true)) {
                $this->error(sprintf(
                    'Invalid --mode [%s]; expected one of: %s.',
                    $given,
                    implode(', ', InstallMode::modes()),
                ));

                return null;
            }

            return $given;
        }

        if ($this->input->isInteractive()) {
            return $this->choice('Install mode', InstallMode::modes(), 'mcp');
        }

        return 'mcp';
    }

    /**
     * Resolve the remote url to commit to .agent-mcp.json.
     *
     * Precedence (for cli mode): --url flag (validated, fail-closed), then an
     * interactive prompt when the session is interactive (blank = none), then the
     * existing committed url preserved unchanged so a non-interactive re-run (CI)
     * never silently wipes a previously set url. For mcp mode, --url is allowed
     * but the value is still validated; the usual path there is null.
     *
     * Returns the resolved url string (null = no url), or false when validation
     * fails (the caller must map false to a non-zero exit without writing).
     *
     * @return string|null|false Resolved url, null for "no url", false on invalid input.
     */
    private function resolveUrl(string $mode): string|null|false
    {
        $given = $this->option('url');

        // 1. An explicit --url is validated first; an invalid value fails closed.
        if ($given !== null && $given !== '') {
            if (! RemoteUrl::valid($given)) {
                $this->error(
                    'Invalid --url: the endpoint must be an https URL (or http for loopback only).',
                );

                return false;
            }

            return $given;
        }

        // 2. In cli mode + interactive bare install (no explicit --mode flag): prompt for an
        //    optional url. Scripted runs that pass --mode explicitly skip the prompt so
        //    non-interactive CI pipelines can re-run safely without registering a question.
        if ($mode === 'cli' && $this->option('mode') === null && $this->input->isInteractive()) {
            $answer = $this->ask('Remote endpoint URL (leave blank for none)', '');

            if ($answer !== null && $answer !== '') {
                if (! RemoteUrl::valid($answer)) {
                    $this->error(
                        'Invalid URL: the endpoint must be an https URL (or http for loopback only).',
                    );

                    return false;
                }

                return $answer;
            }
        }

        // 3. Non-interactive (or mcp mode without --url): preserve the existing committed
        //    url so a CI re-run never silently wipes what was previously recorded.
        return InstallMode::url();
    }

    /**
     * Record the resolved mode (and optional url) to the committed .agent-mcp.json.
     *
     * Re-running with a different mode overwrites the file; the printed note makes
     * that explicit. The mode was already validated in resolveMode() and the url
     * was already validated in resolveUrl(); a real write failure (e.g. a read-only
     * project dir) propagates out of write() and aborts the command rather than
     * reporting success.
     */
    private function recordMode(string $mode, ?string $url): void
    {
        InstallMode::write($mode, $url);

        $this->line(sprintf('Recorded install mode [%s] in %s (commit this file).', $mode, InstallMode::path()));
        $this->line('Re-running with a different --mode overwrites it.');
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
     * Print the ready-to-paste HTTP and stdio bridge client config blocks (mcp mode).
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
     * Print the claude mcp add one-liner for the HTTP endpoint (mcp mode).
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
     * Print the agent-mcp:call / agent-mcp:tools CLI usage block (cli mode).
     *
     * CLI mode skips the MCP client config; the adopter calls the read-only tools
     * straight from the shell instead, locally or against a remote endpoint declared
     * in .agent-mcp.json (the "url" key) or via the AGENT_MCP_URL env override.
     * The same per-tool gate, audit log, and redaction apply in both modes.
     *
     * The committed url is a credential-routing decision: the AGENT_MCP_KEY Bearer
     * token is sent to whatever host is recorded. Change the url only after review.
     */
    private function printCliUsageBlock(): void
    {
        $this->info('=== CLI usage (agent-mcp:call) ===');
        $this->newLine();
        $this->line('CLI mode skips the MCP client config. Call the read-only tools from the shell:');
        $this->newLine();
        $this->line('List the tools you can call (add --all to include disabled ones):');
        $this->line('  php artisan agent-mcp:tools');
        $this->newLine();
        $this->line('Inspect a tool\'s input shape:');
        $this->line('  php artisan agent-mcp:schema db_schema');
        $this->newLine();
        $this->line('Call a tool with a JSON arguments object (positional or on STDIN):');
        $this->line('  php artisan agent-mcp:call db_schema \'{"table":"users"}\'');
        $this->line('  echo \'{"table":"users"}\' | php artisan agent-mcp:call db_schema');
        $this->newLine();
        $this->line('Local mode (default): nothing to configure; the call runs in-process.');
        $this->newLine();
        $this->line('Remote mode: set a committed URL in .agent-mcp.json (set during install via');
        $this->line('--url or the prompt, then commit the file) and keep AGENT_MCP_KEY in .env.');
        $this->line('The command forwards to the remote endpoint using the committed URL. Env');
        $this->line('AGENT_MCP_URL overrides the committed value at runtime.');
        $this->newLine();
        $this->line('URL must be https (http is allowed only for loopback: localhost, 127.0.0.1).');
        $this->line('Committing a URL is a credential-routing decision: the Bearer key is sent to');
        $this->line('that host. Review the URL before committing.');
        $this->newLine();
        $this->line('Sensitive tools (config_inspect, db_slow_queries, db_active_locks, cache_keys,');
        $this->line('run_artisan) are off by default. When enabled, the CLI refuses to print their');
        $this->line('result to a terminal unless you pass --allow-tty; piping or redirecting is allowed.');
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

    /**
     * Print the laravel-boost next-step so it injects the active-mode assets.
     *
     * Boost discovers a package's skills/guidelines only when the package is
     * selected in boost:install (or rediscovered via boost:update --discover). The
     * shipped blades read the recorded mode at render time, so this step is what
     * makes the active-mode skill + guideline land. Boost is never auto-run.
     */
    private function printBoostNextStep(string $mode): void
    {
        $this->info('=== Next step: let Laravel Boost inject the assets ===');
        $this->newLine();
        $this->line(sprintf('Recorded mode is [%s]. Laravel Boost injects only the active mode\'s', $mode));
        $this->line('skill + guideline by reading .agent-mcp.json at render time.');
        $this->newLine();

        if ($this->boostIsInstalled()) {
            $this->line('Boost is installed. Run it to inject (or rediscover) the assets:');
            $this->line('  php artisan boost:install');
            $this->line('  # already set up? rediscover packages instead:');
            $this->line('  php artisan boost:update --discover');
            $this->newLine();
            $this->line('Sail users: prefix with vendor/bin/sail, e.g. vendor/bin/sail artisan boost:install.');
            $this->newLine();

            return;
        }

        $this->line('Laravel Boost is not installed. Install it first, then run boost:install:');
        $this->line('  composer require laravel/boost --dev');
        $this->line('  php artisan boost:install');
        $this->line('Sail users: prefix with vendor/bin/sail, e.g. vendor/bin/sail artisan boost:install.');
        $this->newLine();
    }

    /**
     * Detect whether laravel-boost is installed in the consumer application.
     *
     * The service provider class is the reliable signal: it is autoloaded only
     * when the package is present, so class_exists() without autoload side effects
     * is enough to branch the printed guidance. The FQCN is referenced as a
     * string (not a use import) because laravel-boost is not a dependency of this
     * package; the class exists only in a consumer that has installed boost.
     */
    private function boostIsInstalled(): bool
    {
        return class_exists('Laravel\Boost\BoostServiceProvider');
    }
}
