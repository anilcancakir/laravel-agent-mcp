# agent-mcp tool reference

Sections, parameters, caps and default state of the 14 tools. Every tool is read-only and gated by `config('agent-mcp.tools.<name>')`, and its output goes through a best-effort redactor. A tool with sections uses its first listed section when none is given and refuses an unknown one with the valid names. Tools marked OFF are disabled by default and return a denial until the operator enables them.

## Contents

- Database: `db_schema`, `db_query`, `db_raw_select`, `migrations_status`, `db_health`, `db_diagnostics` (OFF)
- Logs and artisan: `read_logs`, `run_artisan` (OFF)
- Queue: `queue_status`
- Cache: `cache`, `cache_keys` (OFF)
- App: `routes`, `app_info`, `config_inspect` (OFF)

## db_schema

Live tables, columns, indexes and foreign keys, as they are in the running database. ON, uncapped.
- `table` (optional): omit to list every table with its size; give one for its `columns`, `indexes` and `foreign_keys`. An unknown name is rejected.

## db_query

Structured, bound read of one table. ON.
- `table` (required); `query_type` is `find` (one row by `id`), `where` (default, filtered list) or `count`.
- `conditions`: objects `{column, operator, value}` with operator `=`, `!=`, `<`, `>`, `<=`, `>=`, `like` or `in`. `select`, `order_by` and `order_dir` (`asc` default, `desc`) are optional.
- Unknown tables, columns and operators are rejected before the query runs; values are always bound.
- Returns `{row}`, `{rows}` or `{count}`. A where list is capped at `agent-mcp.query.max_rows` (default 50), `limit` can only lower it, and `truncated: true` marks a cut list.

## db_raw_select

One ad-hoc SELECT or read-only CTE for joins, subqueries, aggregates and window functions. ON.
- `sql` (required). Writes, stacked statements and file functions are refused before execution, and a rejection never echoes the SQL.
- An explicit `LIMIT` must be a plain number within the `max_rows` cap, otherwise the call is refused; without one the cap applies.
- Returns `{rows, truncated?}`.

## migrations_status

Migrations that ran, read from the migrations table. ON, no parameters.
- Returns `ran_count`, `latest_batch` and `batches`, each migration name once under its batch.
- Pending migrations are not detected because that needs the filesystem; `pending_detection` says so. A missing migrations table reports `migrations_table_present: false`.

## db_health

Table sizes, index inventory and missing foreign-key indexes. ON.
- `section`: `sizes` (default), `indexes`, `missing_fk`. `table` scopes it to one table; `limit` caps the tables (default 50, at most 200), and `total` with `truncated` appear when tables were cut.
- PostgreSQL adds dead-tuple percentages, unused-index detection and a sequential-scan advisory; MySQL sizes are storage-engine estimates; SQLite sizes need `dbstat` and otherwise fall back to the whole-file size, ignoring `table`.
- `missing_fk` is definitive on PostgreSQL and MySQL and a labelled heuristic on SQLite.

## db_diagnostics

Server diagnostics that need extra database privileges. OFF; one flag opens both sections.
- `section`: `slow_queries` (default, top statements by mean time; `limit` default 20, at most 100) or `locks` (blocked and blocking sessions, a point-in-time snapshot).
- Needs `pg_stat_statements` or `pg_monitor` on PostgreSQL and `performance_schema` on MySQL; otherwise `{available:false, reason}`, always on SQLite.

## read_logs

Tail of the application log. ON.
- `lines` is clamped to `agent-mcp.logs.max_lines` (default 100); `level` is `error`, `warning`, `info` or `debug`.
- A cut tail starts with a `(truncated: showing the last N lines)` line. Output is redacted.

## run_artisan

One artisan command from an exact operator allowlist, the only tool that executes anything. OFF, with an empty allowlist.
- `command` must match an allowlisted name exactly; `arguments` may carry only the options the allowlist permits. Anything else is refused.

## queue_status

Queue health. ON.
- `section`: `backlog` (default), `failed`, `horizon`. `connection` and `queue` scope backlog and failed.
- `backlog`: pending count per connection and queue; the database driver adds `strict_pending`, the sync driver returns a not-applicable note.
- `failed`: newest first, `{id, uuid, connection, queue, job_class, max_tries, timeout, exception_summary, failed_at}`, never the raw payload. `limit` defaults to 25 (at most 100) and the result carries `total` and `truncated`. `summary: true` returns counts per connection and queue instead.
- `horizon`: workload, jobs, metrics and supervisors, or `{available:false}` when Horizon is not installed.

## cache

Cache status or one key. ON.
- No `key`: default store, prefix, stores and drivers, optimization state (config, routes, events cached), an opcache summary and `session_overlap_risk`.
- With `key` (without the global prefix): `exists`, `ttl_seconds` and `value_type` from the default store, or the one named by `store`.
- The value stays `[REDACTED]` unless `raw_value` is true, `agent-mcp.cache.allow_value_read` is on and the key is not block-listed.

## cache_keys

Cache keys with their TTLs. OFF.
- `store` (optional, default store). Database and Redis drivers list keys (Redis is walked with SCAN); the file store reports counts only; other drivers return an opaque marker. Session-prefix keys are excluded.

## routes

Route list or one route. ON.
- Give `name` (wins) or `uri` (no leading slash) for one route, including its `defaults`; an unknown route is an error.
- Otherwise a list narrowed by any of `method`, `uri_prefix`, `name_pattern` (`Str::is` wildcard), `middleware`, `exclude_middleware` (for example `auth`, to find unprotected routes), `domain` and `only_fallback`.
- Rows carry methods, URI, name, action, controller, middleware names (closures as `[Closure]`) and wheres. Controllers are never instantiated.

## app_info

Static application surface. ON.
- `section`: `about` (default), `env_keys`, `storage`, `schedule`, `events`.
- `about` returns environment, cache, drivers, opcache and extensions; narrow it with `parts`.
- `env_keys` returns sorted names and a count, never values.
- `storage` returns the default disk, disks with credential keys stripped, and the link map.
- `schedule` returns cron expression, command, timezone, flags and next run; closures show file and line.
- `events` returns listeners with queue and broadcast flags, wildcards included; `filter` is a case-sensitive substring of the event name.

## config_inspect

Config tree with gated values. OFF, safe-list gated.
- `key` (required) is a dot-path or file such as `app` or `database.connections.mysql`; by default only paths and value types come back.
- A value is revealed only when `reveal_values` is true, the path is in the operator `safe_list` (plus `safe_keys`) and it is not block-listed. The block-list always wins, so keys, passwords and DSNs stay `[REDACTED]`.
