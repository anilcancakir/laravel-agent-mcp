<?php

namespace Anilcancakir\LaravelAgentMcp\Commands;

use Anilcancakir\LaravelAgentMcp\Cli\AbstractMcpCliCommand;
use Anilcancakir\LaravelAgentMcp\Cli\RemoteInvocationException;
use Illuminate\Support\Facades\App;

/**
 * agent-mcp:tools: list the tools available via the CLI.
 *
 * Local mode reads the tool roster (AgentMcpServer) and reports each tool's name,
 * description, and enabled state; by default only enabled (registerable) tools are listed,
 * and --all includes disabled ones flagged enabled=false. Remote mode returns the server's
 * already-enabled tool list. Output is a JSON array (pretty on a terminal, raw when piped).
 */
class ListToolsCommand extends AbstractMcpCliCommand
{
    /** @var string */
    protected $signature = 'agent-mcp:tools
        {--all : Include tools that are disabled in config}
        {--remote : Force remote mode (query the configured url: committed .agent-mcp.json url or AGENT_MCP_URL)}
        {--local : Force local mode (read the local roster)}';

    /** @var string */
    protected $description = 'List the agent-mcp tools available via the CLI.';

    public function handle(): int
    {
        if (! $this->ensureEnabled()) {
            return self::FAILURE;
        }

        try {
            $tools = $this->resolveMode() === 'remote'
                ? $this->remoteList()
                : $this->localList((bool) $this->option('all'));
        } catch (RemoteInvocationException $exception) {
            $this->writeError($exception->getMessage());

            return self::FAILURE;
        }

        $json = json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';

        return $this->writeResult($json, false, false);
    }

    /**
     * The local tool roster as {name, description, enabled} rows, sorted by name. Disabled
     * tools are included only when $all is true.
     *
     * @return array<int, array<string, mixed>>
     */
    private function localList(bool $all): array
    {
        $rows = [];

        foreach ($this->knownTools() as $name => $class) {
            $tool = App::make($class);
            $enabled = $tool->shouldRegister();

            if (! $all && ! $enabled) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'description' => $tool->description(),
                'enabled' => $enabled,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /**
     * The remote tool list (already enabled-filtered server-side) as {name, description, enabled} rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function remoteList(): array
    {
        return array_map(fn (array $tool): array => [
            'name' => $tool['name'] ?? null,
            'description' => $tool['description'] ?? null,
            'enabled' => true,
        ], $this->remoteClient()->listTools());
    }
}
