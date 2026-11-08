<?php

namespace Anilcancakir\LaravelAgentMcp\Support;

/**
 * The tool flags 2.0 retired, and the one warning that tells an operator about them.
 *
 * A config published before 2.0 keeps its 25 old keys. They are reported, never
 * honoured: a 1.x key silently gating a 2.0 tool would be a hidden alias, so the
 * replacement flag alone governs every section the old keys used to split.
 *
 * The warning is returned as text for a command to print and is never written to the
 * application log. read_logs tails that log, and a warning logged on every console boot
 * (schedule:run included) would fill it with noise the agent reads back.
 */
final class RetiredToolKeys
{
    /**
     * Each retired key mapped to the tool that replaced it and what that tool's flag opens.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array REPLACEMENTS = [
        'list_routes' => ['routes', 'modes list and detail'],
        'inspect_route' => ['routes', 'modes list and detail'],
        'queue_backlog' => ['queue_status', 'sections backlog, failed, horizon'],
        'queue_failed_jobs' => ['queue_status', 'sections backlog, failed, horizon'],
        'horizon_status' => ['queue_status', 'sections backlog, failed, horizon'],
        'db_index_health' => ['db_health', 'sections indexes, missing_fk, sizes'],
        'db_missing_fk_indexes' => ['db_health', 'sections indexes, missing_fk, sizes'],
        'db_table_sizes' => ['db_health', 'sections indexes, missing_fk, sizes'],
        'db_slow_queries' => ['db_diagnostics', 'sections slow_queries, locks; default OFF'],
        'db_active_locks' => ['db_diagnostics', 'sections slow_queries, locks; default OFF'],
        'cache_status' => ['cache', 'modes status and inspect'],
        'cache_inspect' => ['cache', 'modes status and inspect'],
        'app_about' => ['app_info', 'sections about, schedule, events, storage, env_keys'],
        'schedule_list' => ['app_info', 'sections about, schedule, events, storage, env_keys'],
        'event_list' => ['app_info', 'sections about, schedule, events, storage, env_keys'],
        'storage_info' => ['app_info', 'sections about, schedule, events, storage, env_keys'],
        'env_keys' => ['app_info', 'sections about, schedule, events, storage, env_keys'],
    ];

    /**
     * One aggregated warning naming every retired key still present in agent-mcp.tools,
     * or null when the config holds none (a fresh install, or a config already cleaned).
     */
    public static function warning(): ?string
    {
        $tools = config('agent-mcp.tools');

        if (! is_array($tools)) {
            return null;
        }

        $notes = [];

        foreach (array_intersect_key(self::REPLACEMENTS, $tools) as $key => [$tool, $opens]) {
            $notes[] = "{$key} => `{$tool}` ({$opens})";
        }

        if ($notes === []) {
            return null;
        }

        return 'agent-mcp: agent-mcp.tools holds keys retired in 2.0, which are ignored: '
            .implode('; ', $notes)
            .'. Each replacement flag now governs every listed section; set it in your published '
            .'config/agent-mcp.php and remove the retired keys.';
    }
}
