# agent-mcp tool reference

Exact inputs, outputs, and caveats for every read-only tool. Read the entry for a tool when you need its precise parameters or are interpreting an unfamiliar field. Every tool is read-only, gated by `config('agent-mcp.tools.<name>')`, and runs its output through a best-effort redactor. Tools marked OFF are disabled by default and return a denial until the operator enables them.

## Contents

- Database schema and queries: `db_schema`, `db_query`, `db_raw_select`
- Database health: `db_index_health`, `db_missing_fk_indexes`, `db_table_sizes`, `migrations_status`, `db_slow_queries` (OFF), `db_active_locks` (OFF)
- Logs and artisan: `read_logs`, `run_artisan` (OFF)
- Queue: `queue_backlog`, `queue_failed_jobs`, `horizon_status`
- Cache: `cache_status`, `cache_inspect`, `cache_keys` (OFF)
- App introspection: `list_routes`, `inspect_route`, `app_about`, `schedule_list`, `event_list`, `storage_info`, `env_keys`, `config_inspect` (OFF)

## Database schema and queries

### db_schema
Inspect the live schema on the read-only connection.
- Params: `table` (string, optional).
- Returns: with no `table`, the list of tables with sizes; with `table`, that table's `columns`, `indexes`, and `foreign_keys`.
- Caveat: an unknown table name is rejected with a clean error. Call this before any query.

### db_query
Structured, parameterized read against one table.
- Params: `table` (string, required); `query_type` (enum `find` | `where` | `count`, required); `id` (integer, for `find`); `conditions` (array of objects, each `{column, operator, value}`); `select` (array of columns, defaults to all); `limit` (integer, clamped to `query.max_rows`); `order_by` (string); `order_dir` (enum `asc` | `desc`, default `asc`).
- Allowed operators: `=`, `!=`, `<`, `>`, `<=`, `>=`, `like`, `in`. Any other operator is rejected before the query runs.
- Returns: `{row}` for find, `{rows}` for where, `{count}` for count.
- Caveat: table and column names are validated against the live schema; values are always bound as parameters.

### db_raw_select
Ad-hoc read-only SELECT supplied as raw SQL.
- Params: `sql` (string, required, a single SELECT or read-only CTE).
- Returns: the result rows.
- Caveat: a SELECT-only grammar validator rejects writes, stacked statements, and file functions before execution; a row LIMIT is applied automatically when omitted. On rejection the offending SQL is not echoed back. Use only when `db_query` cannot express the query.

## Database health

### db_index_health
Index inventory and advisory per table.
- Params: `table` (string, optional; omit to scan all).
- Returns: indexes per table. PostgreSQL adds unused-index detection (`idx_scan = 0`, excluding unique/constraint/partial) and a sequential-scan advisory; MySQL uses `information_schema.STATISTICS`; SQLite uses `pragma_index_list`.

### db_missing_fk_indexes
Foreign-key columns without a covering index.
- Params: `table` (string, optional).
- Returns: the uncovered foreign-key columns. PostgreSQL and MySQL are definitive; SQLite is heuristic and labelled as such.

### db_table_sizes
Per-table storage and row counts.
- Params: `table` (string, optional).
- Returns: PostgreSQL gives total/table/index bytes plus live and dead tuple counts and `dead_pct`; MySQL gives `information_schema.TABLES` estimates; SQLite probes `dbstat` for exact bytes or degrades to whole-database page size.

### migrations_status
Which migrations have run.
- Params: none.
- Returns: the ran list grouped by batch and the latest batch. Pending detection needs the filesystem and is intentionally not performed; degrades gracefully when the migrations table is absent.

### db_slow_queries (OFF)
Top statements by mean execution time.
- Params: `limit` (integer, default 20, capped at 100).
- Returns: the slowest statements. PostgreSQL requires `pg_stat_statements`; MySQL requires `performance_schema`; otherwise `{available:false}`. Query text can be null without `pg_monitor` / `pg_read_all_stats`; numeric stats are still emitted.

### db_active_locks (OFF)
Point-in-time blocked and blocking sessions.
- Params: none.
- Returns: blocked/blocking pairs (PID, user, query). PostgreSQL reads `pg_locks` + `pg_stat_activity` (full visibility needs `pg_monitor`); MySQL reads `PROCESSLIST` + `performance_schema`; SQLite returns `{available:false}`.
- Caveat: a snapshot at the instant of the call, not a live view.

## Logs and artisan

### read_logs
Tail the application log channel.
- Params: `lines` (integer, clamped to `logs.max_lines`, default 200); `level` (enum `error` | `warning` | `info` | `debug`, optional).
- Returns: the trailing lines, optionally filtered by level, redacted. Call it immediately on a 500, exception, or failed job.

### run_artisan (OFF)
Run an exact operator-allowlisted artisan command.
- Params: `command` (string, required, exact allowlist match, no wildcards); `arguments` (object mapping option name to value, only allowlisted options accepted).
- Returns: the command output, redacted.
- Caveat: empty allowlist by default, so it denies until configured. The only tool that executes anything; assume it is unavailable unless confirmed.

## Queue

### queue_backlog
Pending job counts.
- Params: `connection` (string, optional); `queue` (string, optional).
- Returns: per connection and queue, the pending size; the database driver adds a strict-pending count; the sync driver returns a not-applicable note.

### queue_failed_jobs
Failed background jobs.
- Params: `summary` (boolean, optional); `connection` (string, optional); `queue` (string, optional).
- Returns: with `summary` true, counts grouped by connection and queue; otherwise per-job `{id, uuid, connection, queue, job_class, max_tries, timeout, exception_summary, failed_at}`. The raw job payload is never emitted.

### horizon_status
Laravel Horizon snapshot.
- Params: none.
- Returns: `{available:true, workload, jobs, metrics, supervisors, master_supervisors}` when Horizon is installed, otherwise `{available:false}`.

## Cache

### cache_status
Cache subsystem snapshot.
- Params: none.
- Returns: default store, global prefix, configured stores and drivers, optimization state (config / routes / events cached), an opcache summary, and `session_overlap_risk` (true when session and cache share a Redis connection).

### cache_inspect
Inspect one cache key.
- Params: `store` (string, optional, default store); `key` (string, required, without the global prefix); `raw_value` (boolean, optional).
- Returns: `exists`, `ttl_seconds`, `value_type`, and `value`. The value is `[REDACTED]` unless `raw_value` is true AND `cache.allow_value_read` is true AND the key is not block-listed.

### cache_keys (OFF)
Enumerate cache keys with TTLs.
- Params: `store` (string, optional).
- Returns: keys with TTLs on the database and Redis drivers (Redis uses SCAN, never KEYS; session-prefix keys excluded). File driver returns counts only; other drivers return an opaque marker.

## App introspection

### list_routes
Registered routes with filters.
- Params (all optional): `method`, `uri_prefix`, `name_pattern` (Str::is wildcard), `middleware`, `domain`, `exclude_middleware`, `only_fallback` (boolean).
- Returns: routes with methods, URI, name, action, controller class, middleware (names only; closures shown as `[Closure]`), and where constraints. Controllers are never instantiated.

### inspect_route
One route in full.
- Params: `name` (string) or `uri` (string, without leading slash); one is required.
- Returns: the same shape as a `list_routes` row plus `defaults`.

### app_about
Environment snapshot (mirrors `php artisan about`).
- Params: `sections` (array, optional: environment, cache, drivers, opcache, extensions).
- Returns: the requested sections or all: versions, environment, debug and maintenance flags, drivers, cache state, opcache, loaded extensions.

### schedule_list
Scheduled tasks.
- Params: none.
- Returns: per event the cron `expression`, `command`, `description`, `timezone`, overlap and single-server flags, environments, and the next run time. Closure events are reported as a callback with file and line.

### event_list
Registered event listeners.
- Params: `filter` (string, optional, case-sensitive substring).
- Returns: per event the listeners, classified as string, `[class, method]`, or closure (file and line), with `should_queue` and `should_broadcast` flags. Wildcard listeners are included.

### storage_info
Filesystem disks and public links.
- Params: none.
- Returns: the default disk, the disks (credential keys such as key, secret, password, token stripped), and the symlink map with a liveness check. Never creates or removes links.

### env_keys
Environment variable names.
- Params: none.
- Returns: the sorted key names and a count. Values are never returned. To read a config value, use `config_inspect`.

### config_inspect (OFF)
Config tree with optional value reveal.
- Params: `key` (string, required, dot-path or file such as `app` or `database.connections.mysql`); `reveal_values` (boolean, optional); `safe_keys` (array of dot-paths, optional).
- Returns: the key tree with value types. A value is revealed only when `reveal_values` is true AND the dot-path is in the operator `safe_list` (unioned with `safe_keys`) AND it is not block-listed. The block-list (covering `url`, `dsn`, `key`, `password`, `secret`, `token`, and more) always wins, so credentials stay `[REDACTED]` even when explicitly requested.
