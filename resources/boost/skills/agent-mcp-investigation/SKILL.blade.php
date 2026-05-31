@if(\Anilcancakir\LaravelAgentMcp\Support\InstallMode::current() === 'mcp')
---
name: agent-mcp-investigation
description: "Use when investigating a running Laravel application's database, runtime state, queue health, cache, or errors through the agent-mcp read-only MCP tools. Triggers on inspecting database schema, running read-only queries to find or count records, reading application logs to diagnose a 500, exception, or failed job, checking queue backlog or failed jobs, inspecting cache or optimization state, auditing routes, schedule, event listeners, config structure, or storage layout, and running an operator-allowlisted artisan command. Use whenever the answer depends on live data rather than source code, even when the user does not name the tools. Do NOT trigger for write operations (inserts, updates, deletes, migrations, schema changes) or for projects without the agent-mcp endpoint."
license: MIT
metadata:
  author: anilcancakir
---
# Agent MCP Investigation

Investigate a live Laravel application through the read-only MCP tools served at
`/agent-mcp`. Authentication is a single server-admin key (`AGENT_MCP_KEY`,
presented as `Authorization: Bearer <key>`): there is no user login and no
per-user token. Every tool is read-only with no database write access, so call
them freely. The live database and logs are the source of truth; code on disk
may not match what is deployed.

Some tools are off by default and require the operator to enable them
(`config_inspect`, `db_slow_queries`, `db_active_locks`, `cache_keys`). Treat a
tool-denied response as the expected boundary, not an error to route around.

## Workflow

### 1. Inspect the schema first

Call `db_schema` before writing any query. With no arguments it lists the
tables; pass a `table` argument for that table's columns, types, indexes, and
foreign keys. Confirm the real shape here rather than inferring it from models
or migrations, which may lag the deployed database. Re-check whenever you meet
an unknown table or column.

### 2. Query read-only

Pick the narrower tool that does the job:

- `db_query` for structured lookups where you know the table and filters (find
  by id, filter by a column, count rows). Table and column names are validated
  against the live schema and values are bound as parameters. Prefer this for
  routine reads.
- `db_raw_select` for queries the builder cannot express: JOINs, subqueries,
  aggregations, window functions. Supply a single SELECT statement. A
  SELECT-only grammar guard rejects anything that is not a read-only SELECT, and
  a row limit is applied automatically.

### 3. Read logs on any failure

On a 500, exception, failed job, or behavior you cannot explain, call
`read_logs` immediately, before attempting a fix. The log context is usually the
fastest path to the root cause. Control volume with `lines` and narrow with
`level` (error, warning, info, debug). Output passes through best-effort secret
redaction, so treat it as a diagnostic aid rather than a guarantee that no
sensitive value appears.

### 4. Investigate queue health

When a user reports slow or missing background processing:

1. Call `queue_backlog` to check pending depth per connection and queue.
2. Call `queue_failed_jobs` for failed-job counts and per-job detail (job class,
   exception first line, `failed_at`). Raw payloads are never returned.
3. If the application uses Laravel Horizon, call `horizon_status` for workload,
   metrics, and supervisor state. It returns `{available:false}` when Horizon is
   not installed; that is expected, not an error.

### 5. Investigate database health

For slow queries, index gaps, or disk usage:

- `db_index_health`: index list per table with unused-index detection (PG) and
  seq-scan advisory. Optional `table` argument to scope.
- `db_missing_fk_indexes`: find foreign-key columns without a covering index.
- `db_table_sizes`: row counts and storage sizes; PG includes dead-tuple
  percentage.
- `migrations_status`: which migrations have run and in which batch.
- `db_slow_queries` (operator opt-in): top queries by mean execution time. PG
  requires `pg_stat_statements`; MySQL requires `performance_schema`. Returns
  `{available:false}` when absent. Results may be partial without `pg_monitor`
  or `pg_read_all_stats` (PG) or `performance_schema` access (MySQL).
- `db_active_locks` (operator opt-in): blocked/blocking queries at the moment of
  the call (point-in-time snapshot). Returns `{available:false}` on SQLite.

### 6. Investigate cache and optimization state

- `cache_status`: start here. Reports stores, config/routes/events cached state,
  opcache summary, and session-cache overlap risk.
- `cache_inspect`: metadata (exists, TTL, value type) for a specific key. Raw
  value is available only when the operator has set `cache.allow_value_read=true`
  AND the key passes the block-list.
- `cache_keys` (operator opt-in): lists keys with TTLs. Database and Redis
  drivers only. Redis excludes the session prefix to prevent session ID leakage.

### 7. Audit app structure

- `list_routes` / `inspect_route`: all registered routes, middleware (names
  only; no signed-route secrets), filters by method/prefix/name/middleware.
- `app_about`: environment, versions, debug flag, maintenance state, cache and
  driver summary. Mirrors `php artisan about`.
- `schedule_list`: scheduled events, cron expressions, next-run timestamps, and
  scheduling flags.
- `event_list`: all registered listeners including wildcards. Classifies string,
  Closure (file:line), and `[class, method]` shapes; flags `ShouldQueue` and
  `ShouldBroadcast`.
- `storage_info`: disk config (driver, root, visibility) with credentials
  stripped; symlink map with liveness check.
- `env_keys`: names of all process environment variables. Values are never
  returned.
- `config_inspect` (operator opt-in): config key tree with types. Values require
  `reveal_values=true` AND the dot-path in `safe_list` AND not matched by the
  block-list. Block-list covers `url`, `dsn`, `key`, `password`, `secret`,
  `token`, and more; it always wins. Redaction is the final net, not the primary
  gate.

### 8. Run an allowlisted artisan command (only if enabled)

`run_artisan` is disabled by default. It runs only when the operator has enabled
it and configured an exact-match command allowlist; options are authorized
explicitly, with no wildcards. Do not assume it is available. If a command is
denied, that is the expected boundary, not an error to route around.

## Verification

1. Before querying, confirm the table and columns exist via `db_schema`.
2. After a read, sanity-check the row count and shape against what the schema
   implied.
3. When diagnosing a failure, confirm the log line you found actually matches
   the reported symptom (timestamp, level, message) before proposing a cause.
4. For queue investigations, correlate `queue_backlog` depth with `queue_failed_jobs`
   error patterns before concluding a cause.

## Common pitfalls

- Inferring schema from model or migration files instead of calling `db_schema`
  against the live database.
- Reaching for `db_raw_select` when `db_query` would express the lookup more
  safely.
- Proposing a fix for an error before reading the logs with `read_logs`.
- Assuming `run_artisan` is available; it is off unless the operator enabled it.
- Assuming `config_inspect`, `db_slow_queries`, `db_active_locks`, or
  `cache_keys` are available; they are off by default.
- Expecting `db_slow_queries` or `db_active_locks` to have complete data without
  the appropriate DB grants (`pg_monitor`/`pg_read_all_stats` for PG,
  `performance_schema` access for MySQL).
- Treating `{available:false}` from `horizon_status`, `db_slow_queries`, or
  `db_active_locks` as an error; it means the backend is simply not present or
  not accessible, not that the tool is broken.
- Expecting write access: these tools only read. Surface any change you want the
  operator to make rather than attempting it here.
@endif
