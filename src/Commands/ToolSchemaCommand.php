<?php

namespace Anilcancakir\LaravelAgentMcp\Commands;

use Anilcancakir\LaravelAgentMcp\Cli\AbstractMcpCliCommand;
use Anilcancakir\LaravelAgentMcp\Cli\RemoteInvocationException;
use Illuminate\Support\Facades\App;
use RuntimeException;

/**
 * agent-mcp:schema: show the input schema of a single tool.
 *
 * Local mode renders the tool's own inputSchema (built from its schema() definition via the
 * laravel/mcp Tool::toArray contract); remote mode pulls the tool's inputSchema from the
 * server's tools/list. Output is the {name, description, inputSchema} JSON. An unknown tool
 * exits non-zero with a stderr diagnostic. No tool is invoked.
 */
class ToolSchemaCommand extends AbstractMcpCliCommand
{
    /** @var string */
    protected $signature = 'agent-mcp:schema
        {tool : The tool name (e.g. db_schema)}
        {--remote : Force remote mode (query AGENT_MCP_URL)}
        {--local : Force local mode (read the local roster)}';

    /** @var string */
    protected $description = 'Show the input schema of an agent-mcp tool.';

    public function handle(): int
    {
        if (! $this->ensureEnabled()) {
            return self::FAILURE;
        }

        $tool = (string) $this->argument('tool');

        try {
            $schema = $this->resolveMode() === 'remote'
                ? $this->remoteSchema($tool)
                : $this->localSchema($tool);
        } catch (RemoteInvocationException|RuntimeException $exception) {
            $this->writeError($exception->getMessage());

            return self::FAILURE;
        }

        $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        return $this->writeResult($json, false, false);
    }

    /**
     * The local tool's {name, description, inputSchema} from its Tool::toArray contract.
     *
     * @return array<string, mixed>
     */
    private function localSchema(string $tool): array
    {
        $known = $this->knownTools();

        if (! isset($known[$tool])) {
            throw new RuntimeException("Unknown tool: {$tool}.");
        }

        $array = App::make($known[$tool])->toArray();

        return [
            'name' => $array['name'] ?? $tool,
            'description' => $array['description'] ?? null,
            'inputSchema' => $array['inputSchema'] ?? [],
        ];
    }

    /**
     * The remote tool's {name, description, inputSchema} located in the server tools/list.
     *
     * @return array<string, mixed>
     */
    private function remoteSchema(string $tool): array
    {
        foreach ($this->remoteClient()->listTools() as $entry) {
            if (($entry['name'] ?? null) === $tool) {
                return [
                    'name' => $tool,
                    'description' => $entry['description'] ?? null,
                    'inputSchema' => $entry['inputSchema'] ?? [],
                ];
            }
        }

        throw new RuntimeException("Unknown tool: {$tool}.");
    }
}
