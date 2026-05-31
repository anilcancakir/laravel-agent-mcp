@if(\Anilcancakir\LaravelAgentMcp\Support\InstallMode::current() === 'mcp')
---
name: agent-mcp-investigation
description: "Use when investigating a running Laravel application through the agent-mcp read-only MCP tools: inspect the live database schema, run read-only queries to find or count records, read application logs to diagnose a 500, exception, or failed job, check queue backlog, failed jobs, or Horizon, inspect cache or optimization state, or audit routes, schedule, events, config, env keys, and storage. Use whenever the answer depends on live runtime state rather than source code, even when the user does not name the tools. Do NOT use for write operations (insert, update, delete, migrate, schema change) or for projects without the /agent-mcp endpoint; for shell-based one-off calls, use the agent-mcp-cli skill instead."
license: MIT
metadata:
  author: anilcancakir
---
# Agent MCP Investigation

Investigate a live Laravel application through the read-only agent-mcp tools exposed over MCP at `/agent-mcp`. The running database and logs are the source of truth: code on disk may not match what is deployed, so ground answers in tool output rather than in models, migrations, or memory. Every tool is read-only and has no write surface, so call them as often as the investigation needs.

Authentication is a single server-admin key, not a user login. Some tools are off by default and must be enabled by the operator (`config_inspect`, `db_slow_queries`, `db_active_locks`, `cache_keys`, `run_artisan`); a disabled tool returns a denial. Treat a denial as the expected boundary, not an error to retry around.

## Start here, by task

- Any database work: call `db_schema` first. With no argument it lists tables; pass `{"table":"<name>"}` for that table's columns, indexes, and foreign keys. Confirm names against the live database instead of inferring them from models or migrations.
- A 500, exception, or failed job: call `read_logs` before proposing a fix, because the log is usually the fastest path to the cause. Narrow with `level` (error, warning, info, debug) and bound volume with `lines`. Reach for it proactively when behavior is odd even without an explicit error.
- Reading data: prefer `db_query` (structured, parameterized, schema-validated) for find, filter, and count. Use `db_raw_select` only for queries the builder cannot express (multi-table JOINs, subqueries, aggregates, window functions); it is SELECT-only and auto-limited.
- Slow background processing: `queue_backlog` for depth, then `queue_failed_jobs` for failure detail, then `horizon_status` when Horizon is deployed.
- Slow queries: `db_slow_queries` (if enabled) for the worst offenders, then `db_index_health` and `db_missing_fk_indexes` for structural causes, and `db_table_sizes` for bloat.
- Cache questions: `cache_status` first (stores, optimization state, session-overlap risk), then `cache_inspect` for one key.
- App shape: `list_routes` and `inspect_route` for routing, `app_about` for environment and versions, plus `schedule_list`, `event_list`, `storage_info`, `env_keys` (names only), and `config_inspect` (structure by default; values are gated).

## Reading results

- Many engine-specific tools return `{available:false}` when a backend or extension is absent (Horizon not installed, no `pg_stat_statements`, SQLite for locks). That means "not available here", not a failure.
- `db_active_locks` is a point-in-time snapshot; a lock may already be gone by the time you read the result.
- Output passes through a best-effort secret redactor, so a value may appear as `[REDACTED]`. Redaction is a safety net, not a guarantee; the read-only database grant is the real boundary.
- Results are capped (row limits, log-line limits), so a large scan can be truncated. Narrow the query rather than assuming the table is small.

## Gotchas

- The sensitive tools above are off by default. Do not assume they are available; plan for a denial.
- `run_artisan` is the only tool that executes anything, and only exact operator-allowlisted commands. There are no write tools, so surface a change you want made rather than attempting it.
- `queue_failed_jobs` never returns the raw job payload, `env_keys` never returns values, and `config_inspect` and `cache_inspect` reveal values only behind explicit operator opt-in.

## Full tool reference

For the exact parameters, return shape, per-engine differences, and caveats of any single tool, read `references/tools.md`. Consult it when you need a tool's precise input or are interpreting an unfamiliar field, rather than guessing. The live input schema is also available from the MCP tool list (or `agent-mcp:schema <tool>` on the CLI).
@endif
