# Agent MCP Tools

This application exposes read-only investigation tools over the Model Context
Protocol at the `/agent-mcp` HTTP endpoint. Access is controlled by a single
server-admin key: the operator sets `AGENT_MCP_KEY` and clients send
`Authorization: Bearer <key>`. There is no user login and no per-user token; the
key grants the full read surface. Every tool is read-only and has no write
access to the database, so they are safe to call as often as you need.

Use these tools to ground answers in the live application state instead of
guessing from model code, migrations, or memory. The running database and logs
are the source of truth; code on disk may not match what is deployed.

Enable only what you want: every tool is individually gated by the operator via
`config('agent-mcp.tools.<name>')`. Sensitive tools (`config_inspect`,
`db_slow_queries`, `db_active_locks`, `cache_keys`) are off by default and must
be explicitly enabled by the operator. Treat a tool-denied response as expected,
not as an error to route around.

## The core tools and when to reach for each

- `db_schema`: call this BEFORE writing any query. With no arguments it returns
  the table list; pass a `table` argument for that table's columns, types,
  indexes, and foreign keys. Call it again whenever you hit an unknown table or
  column. Do not infer schema from models or migrations.
- `db_query`: structured, parameterized reads when you know the table and the
  filter (find by id, filter by a column, count rows). Table and column names
  are validated against the live schema and values are always bound. Prefer this
  for routine lookups.
- `db_raw_select`: ad-hoc or complex SELECT supplied as raw SQL (JOINs,
  subqueries, aggregations, window functions). A SELECT-only grammar guard
  rejects anything that is not a single read-only SELECT, and a row limit is
  applied automatically. Use it only when the query builder cannot express the
  query.
- `read_logs`: on any 500, exception, failed job, or unexpected behavior, call
  this IMMEDIATELY before attempting a fix. It returns recent application log
  lines; control volume with `lines` and narrow with `level` (error, warning,
  info, debug). A best-effort secret redaction pass runs on the output.
- `run_artisan`: disabled by default. It is available only when the operator has
  explicitly enabled it and configured an exact-match command allowlist. Do not
  assume it is available; it is the highest-risk tool, so treat a denial as
  expected rather than an error to work around.

## Queue investigation tools

- `queue_backlog`: when a user reports slow or missing background processing,
  start here. Returns pending job counts per connection and queue. Sync driver
  returns `{note:N/A}`.
- `queue_failed_jobs`: for failed-job investigation. Returns counts and per-job
  details (job class, exception first line, `failed_at`). Raw payload is never
  emitted.
- `horizon_status`: when the app uses Laravel Horizon, use this for workload,
  metrics, and supervisor state. Returns `{available:false}` when Horizon is not
  installed (detect-then-use; no error).

## Database health tools

- `db_index_health`: lists indexes per table. PG adds unused-index detection
  (via `pg_stat_user_indexes`) and seq-scan advisory. MySQL uses
  `information_schema.STATISTICS`. SQLite uses `pragma_index_list`.
- `db_missing_fk_indexes`: finds foreign-key columns without a covering index.
  PG and MySQL results are definitive; SQLite result is heuristic (labelled).
- `db_table_sizes`: row counts and storage sizes. PG includes dead-tuple
  percentage. MySQL sizes are estimates. SQLite degrades gracefully.
- `migrations_status`: reads the `migrations` table (ran list + batches).
  Pending detection requires the filesystem and is not performed.
- `db_slow_queries` (off by default): top queries by mean execution time. PG
  requires `pg_stat_statements`; MySQL requires `performance_schema`. Returns
  `{available:false}` when absent. Results are privilege-dependent: the readonly
  role needs `pg_monitor` or `pg_read_all_stats` for full PG visibility.
- `db_active_locks` (off by default): blocked/blocking queries and held locks.
  Point-in-time snapshot. PG requires `pg_monitor` for full visibility; partial
  results are labelled. Returns `{available:false}` on SQLite.

## Cache investigation tools

- `cache_status`: start here for cache health questions. Reports all configured
  stores, config/routes/events cached state, opcache summary, and a
  `session_overlap_risk` flag when session and cache share the same Redis
  connection.
- `cache_inspect`: metadata (exists, TTL, value type) for a given key. Raw
  value is delivered only when `cache.allow_value_read=true` AND the key passes
  the key-name block-list; otherwise `[REDACTED]`.
- `cache_keys` (off by default): lists cache keys with TTLs. Database driver
  queries the cache table; Redis uses SCAN (never KEYS) and excludes the session
  prefix to prevent live session IDs from leaking.

## App introspection tools

- `list_routes`: all registered routes with methods, URI, name, controller, and
  middleware (raw + resolved). Filters available: `method`, `uri_prefix`,
  `name_pattern`, `middleware`, `exclude_middleware`. Middleware names only; no
  signed-route secrets.
- `inspect_route`: deep dive on a single route by name or URI.
- `app_about`: application versions, environment, debug flag, maintenance state,
  cache/driver/extension summary. Mirrors `php artisan about`.
- `schedule_list`: all scheduled events with cron expression, next-run
  timestamp, and scheduling flags (withoutOverlapping, onOneServer, etc.).
- `event_list`: all registered listeners including wildcards. Classifies string,
  Closure (file:line), and `[class, method]` shapes; flags `ShouldQueue` and
  `ShouldBroadcast` implementors.
- `storage_info`: filesystem disk config (driver, root, visibility) with
  credentials stripped; symlink map with liveness check.
- `env_keys`: names of all process environment variables. Values are never
  returned.
- `config_inspect` (off by default): config key tree with types by default.
  Values require `reveal_values=true` AND the dot-path in `safe_list` AND not
  matched by the block-list. Block-list covers `url`, `dsn`, `key`, `password`,
  `secret`, `token`, and more; it always wins over `safe_list`. Redaction is the
  final net, not the primary gate.

## CLI access (no MCP server required)

- When the agent-mcp MCP server is not registered, call the same read-only tools
  from the shell: `php artisan agent-mcp:tools` (list), `php artisan agent-mcp:schema
  <tool>` (input schema), `php artisan agent-mcp:call <tool> '<json>'` (invoke; JSON
  args positionally or on STDIN, result to stdout, non-zero exit on error). Use the
  project's artisan form (for example `vendor/bin/sail artisan`) when applicable.
- Use the CLI for one-off calls, scripts, or CI; register the MCP server for
  persistent interactive use. Sensitive tools need `--allow-tty` to print to a
  terminal. See the `agent-mcp-cli` skill for details.

## Working habits

- Open `db_schema` at the start of any database task, not only after a query
  fails. Knowing the real shape up front prevents wrong queries.
- Reach for `read_logs` proactively when a user reports odd behavior, even
  without an explicit error: warnings and deprecations often explain it.
- Prefer `db_query` for simple lookups and keep `db_raw_select` for queries the
  builder cannot express.
- For queue issues: `queue_backlog` for depth, `queue_failed_jobs` for error
  detail, `horizon_status` when Horizon is deployed.
- For slow queries: `db_slow_queries` (if enabled) for the worst offenders, then
  `db_index_health` and `db_missing_fk_indexes` for structural causes.
- For cache issues: `cache_status` first, then `cache_inspect` for a specific
  key.
- `config_inspect`, `db_slow_queries`, `db_active_locks`, and `cache_keys` are
  off by default. Do not assume they are available.
