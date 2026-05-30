<?php

namespace Anilcancakir\LaravelAgentMcp\Tools;

use Anilcancakir\LaravelAgentMcp\Auditing\AuditLogger;
use Anilcancakir\LaravelAgentMcp\Database\ReadonlyConnectionResolver;
use Anilcancakir\LaravelAgentMcp\Logs\LogFileResolver;
use Anilcancakir\LaravelAgentMcp\Logs\LogPathException;
use Anilcancakir\LaravelAgentMcp\Support\OutputRedactor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * MCP tool `read_logs`: tails the application's active log file.
 *
 * The file path is resolved AND containment-checked by LogFileResolver (the
 * path-traversal boundary); this tool never accepts or constructs a path of its
 * own. Output is tailed to the last N lines (clamped to logs.max_lines),
 * optionally filtered by level, then run through the best-effort redactor before
 * it leaves handle().
 */
final class ReadLogsTool extends AbstractAgentTool
{
    /**
     * The accepted level filters, mapped to the substring that identifies the
     * level in a standard Laravel/Monolog line (e.g. "production.ERROR:").
     */
    private const LEVEL_MARKERS = [
        'error' => '.ERROR',
        'warning' => '.WARNING',
        'info' => '.INFO',
        'debug' => '.DEBUG',
    ];

    /**
     * Snake_case tool name matching the config('agent-mcp.tools.read_logs') key
     * the base reads through name() for the enabled / audit surface.
     */
    protected string $name = 'read_logs';

    public function __construct(
        ReadonlyConnectionResolver $connectionResolver,
        OutputRedactor $outputRedactor,
        AuditLogger $auditLogger,
        private readonly LogFileResolver $logFileResolver,
    ) {
        parent::__construct($connectionResolver, $outputRedactor, $auditLogger);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'lines' => $schema->integer()
                ->min(1)
                ->description('How many trailing log lines to return (clamped to the configured maximum).'),

            'level' => $schema->string()
                ->enum(array_keys(self::LEVEL_MARKERS))
                ->description('Optional level filter: error, warning, info, or debug.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // 1. Authoritative tool-enabled gate (base; fail closed).
        if ($denial = $this->authorize()) {
            return $denial;
        }

        // 2. Audit the call shape (never values) before doing work.
        $this->audit($this->argumentShape($request->all()));

        // 3. Resolve + containment-check the log file; reject traversal cleanly.
        try {
            $path = $this->logFileResolver->resolve();
        } catch (LogPathException) {
            return Response::error('The log file could not be read.');
        }

        $limit = $this->resolvedLineCount($request);
        $level = $this->resolvedLevel($request);

        // 4. Tail the file, then apply the optional level filter.
        $lines = $this->tail($path, $limit, $level);

        if ($lines === []) {
            return Response::text('No matching log lines.');
        }

        // 5. Redact before the lines leave the tool.
        return Response::text($this->redactor()->redact(implode("\n", $lines)));
    }

    /**
     * The number of lines to return, clamped to config('agent-mcp.logs.max_lines').
     */
    private function resolvedLineCount(Request $request): int
    {
        $max = (int) config('agent-mcp.logs.max_lines', 200);
        $max = max(1, $max);

        $requested = $request->get('lines');
        $requested = is_numeric($requested) ? (int) $requested : $max;

        return max(1, min($requested, $max));
    }

    /**
     * The validated level filter, or null when none / an unknown value was given.
     */
    private function resolvedLevel(Request $request): ?string
    {
        $level = $request->get('level');

        if (! is_string($level)) {
            return null;
        }

        $level = strtolower($level);

        return isset(self::LEVEL_MARKERS[$level]) ? $level : null;
    }

    /**
     * Read the last $limit lines from $path, applying the optional level filter.
     *
     * Reads the file backwards in chunks so a large log is not loaded whole. When
     * a level filter is active we keep scanning past $limit matched lines only
     * until $limit matches are collected, so the newest matching lines win.
     *
     * @return array<int, string>
     */
    private function tail(string $path, int $limit, ?string $level): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            return $this->readTrailingLines($handle, $limit, $level);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Collect up to $limit trailing (optionally level-matching) lines by reading
     * the stream backwards in fixed chunks.
     *
     * @param  resource  $handle
     * @return array<int, string>
     */
    private function readTrailingLines($handle, int $limit, ?string $level): array
    {
        $chunkSize = 8192;
        $position = fstat($handle)['size'];
        $buffer = '';
        $collected = [];

        while ($position > 0 && count($collected) < $limit) {
            $readSize = (int) min($chunkSize, $position);
            $position -= $readSize;

            fseek($handle, $position);
            $buffer = (string) fread($handle, $readSize).$buffer;

            // Split into complete lines; the first segment may be a partial line
            // whose head lives in an earlier chunk, so hold it back in $buffer.
            $segments = explode("\n", $buffer);
            $buffer = $position > 0 ? array_shift($segments) : '';

            $this->prependMatches($segments, $collected, $limit, $level);
        }

        // Flush the leftover head-of-file line once the start is reached.
        if ($position === 0 && $buffer !== '' && count($collected) < $limit) {
            $this->prependMatches([$buffer], $collected, $limit, $level);
        }

        return $collected;
    }

    /**
     * Prepend newest-first $segments (already in file order) onto $collected,
     * keeping file order in the result and stopping at $limit matches.
     *
     * @param  array<int, string>  $segments
     * @param  array<int, string>  $collected
     */
    private function prependMatches(array $segments, array &$collected, int $limit, ?string $level): void
    {
        // Walk this chunk's lines bottom-up (newest first) so we collect the most
        // recent matches, then restore file order via array_unshift.
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            if (count($collected) >= $limit) {
                return;
            }

            $line = $segments[$i];

            if ($line === '') {
                continue;
            }

            if ($level !== null && ! str_contains($line, self::LEVEL_MARKERS[$level])) {
                continue;
            }

            array_unshift($collected, $line);
        }
    }
}
