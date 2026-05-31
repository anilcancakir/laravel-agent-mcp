# laravel-agent-mcp

A secure MCP (Model Context Protocol) server package for Laravel. It exposes a set of read-only tools that let an authenticated LLM agent inspect your database schema, run safe queries, read application logs, and invoke explicitly allowlisted artisan commands, all behind a server-admin key and a code-enforced read-only database boundary.

## What it does

The package registers an MCP server on your Laravel application over HTTP (Streamable HTTP transport) and/or a local stdio bridge. Every tool is individually enable/disable-able: enable only what you want via `config('agent-mcp.tools.<name>')` in your published `config/agent-mcp.php`. Sensitive tools are off by default; the operator opts in.

### v0.2.0 core tools

| Tool | What it does | Default state |
|------|-------------|---------------|
| `db_schema` | Lists tables, or returns columns, indexes, and foreign keys for a given table | Enabled |
| `db_query` | Structured query builder: find/where/count with bound parameters | Enabled |
| `db_raw_select` | Accepts a raw SQL SELECT, validates it via an allowlist parser, auto-applies a row limit, then executes on the read-only connection | Enabled |
| `read_logs` | Tails the configured log channel, with an optional level filter and output redaction | Enabled |
| `run_artisan` | Runs an artisan command from an explicit allowlist | Disabled by default; opt-in required |

### v0.3.0 investigation tools

Twenty additional read-only investigation tools grouped into four domains. Enable only what you want; sensitive tools are off by default.

#### Queue

| Tool | What it does | Default state |
|------|-------------|---------------|
| `queue_backlog` | Per-connection/queue pending job counts. Wraps `size()` per driver; database driver also counts strict-pending rows. | Enabled |
| `queue_failed_jobs` | Summary counts and per-row details (job class, exception first line, failed\_at) from the failed jobs table. Raw payload is never emitted. | Enabled |
| `horizon_status` | Horizon workload, metrics, and supervisor state. Returns `{available:false}` when Horizon is not installed. | Enabled (availability-gated) |

#### Database health

| Tool | What it does | Default state |
|------|-------------|---------------|
| `db_index_health` | Index list per table; PG adds unused-index detection (via `pg_stat_user_indexes`) and seq-scan advisory; MySQL uses `information_schema.STATISTICS`; SQLite uses `pragma_index_list`. | Enabled |
| `db_missing_fk_indexes` | Finds foreign-key columns without a covering index. PG and MySQL give definitive results; SQLite result is heuristic (labelled). | Enabled |
| `db_table_sizes` | Row counts and storage sizes per table. PG uses `pg_total_relation_size` + dead-tuple stats; MySQL uses `information_schema.TABLES` (estimates); SQLite probes `dbstat` then degrades gracefully. | Enabled |
| `migrations_status` | Reads the `migrations` table (ran list + batches). Pending detection requires the filesystem and is not performed by this tool. | Enabled |
| `db_slow_queries` | Top queries by mean execution time. PG requires `pg_stat_statements`; MySQL requires `performance_schema`. Returns `{available:false}` when the extension/schema is absent. | **Disabled by default** |
| `db_active_locks` | Blocked/blocking queries and held locks at the moment of the call (point-in-time). PG uses `pg_locks + pg_stat_activity`; MySQL uses `information_schema.PROCESSLIST + performance_schema`. Returns `{available:false}` on SQLite. | **Disabled by default** |

#### Cache

| Tool | What it does | Default state |
|------|-------------|---------------|
| `cache_status` | Cache store config, optimization state (config/routes/events cached), opcache summary, and a `session_overlap_risk` flag when session and cache share the same Redis connection. | Enabled |
| `cache_inspect` | Metadata (exists, TTL, value type) for a given cache key. The raw value is returned only when `cache.allow_value_read=true` AND the key does not match the key-name block-list; otherwise it is `[REDACTED]`. | Enabled (raw value gated) |
| `cache_keys` | Lists cache keys with TTLs. Database driver queries the cache table; Redis uses SCAN (never KEYS) and excludes the session prefix to prevent live session IDs from leaking. | **Disabled by default** |

#### App introspection

| Tool | What it does | Default state |
|------|-------------|---------------|
| `list_routes` | All registered routes with methods, URI, name, controller, middleware (raw + resolved), and filters (`method`, `uri_prefix`, `name_pattern`, `middleware`, `exclude_middleware`). Middleware names only; no signed-route keys. | Enabled |
| `inspect_route` | Deep dive on a single route (by name or URI): same shape as `list_routes` plus defaults. | Enabled |
| `app_about` | Application versions, environment, debug flag, maintenance state, cache/driver/extension summary. Mirrors `php artisan about`. | Enabled |
| `schedule_list` | All scheduled events: cron expression, command summary, next run time, flags (withoutOverlapping, onOneServer, etc.). | Enabled |
| `event_list` | All registered event listeners including wildcards. Classifies string, Closure (file:line), and `[class, method]` listeners; flags `ShouldQueue` and `ShouldBroadcast` implementors. | Enabled |
| `storage_info` | Filesystem disk config (driver, root, visibility) with credentials (`key/secret/password/token`) stripped; symlink map with liveness check. | Enabled |
| `env_keys` | Names of all process environment variables (`array_keys($_ENV)`). Values are never returned. | Enabled |
| `config_inspect` | Config key tree with types by default. Values are returned only when `reveal_values=true` AND the dot-path is in `config_inspect.safe_list` AND not matched by the block-list. The block-list always wins, even with explicit opt-in. | **Disabled by default** |

Every tool call is audited and run through best-effort output redaction. DB tools access a read-only connection (code-enforced; a dedicated readonly DB user is strongly recommended).

## Requirements

- PHP `^8.3` (PHP 8.2 is untested: Pest 4, PHPUnit 12, and Testbench 11 all require ^8.3)
- Laravel `11`, `12`, or `13`
- `laravel/mcp` `>=0.6 <0.8` (pre-1.0, tightly pinned; see [Security model](#security-model) for the rationale)

## Version matrix

| PHP | Laravel | Supported |
|-----|---------|-----------|
| 8.3 | 11, 12, 13 | Yes |
| 8.4 | 11, 12, 13 | Yes |
| 8.5 | 11, 12, 13 | Yes |
| 8.2 | any | Untested (toolchain requires ^8.3) |

## Installation

```bash
composer require anilcancakir/laravel-agent-mcp
```

Run the install command to choose your integration mode and publish the config and agent assets:

```bash
php artisan agent-mcp:install
# Sail / Herd: vendor/bin/sail artisan agent-mcp:install
```

The command prompts you to pick a mode (default: `mcp`). To skip the prompt, pass `--mode` directly:

```bash
php artisan agent-mcp:install --mode=mcp
php artisan agent-mcp:install --mode=cli
```

### Install modes

#### MCP mode (default)

Registers a full MCP server on your Laravel app and sets up the client configuration. This mode:

- Publishes `config/agent-mcp.php`, `AGENTS.md`, and `.mcp.json.example`
- Prints ready-to-paste `.mcp.json` blocks (HTTP transport + stdio bridge) and the `claude mcp add` one-liner
- Activates the `agent-mcp-investigation` boost skill (schema, queries, logs, artisan investigation workflow)

Use this mode when you want persistent, low-latency tool access across many turns of an interactive agent session.

#### CLI mode

No MCP server registration required. The package's artisan commands (`agent-mcp:call`, `agent-mcp:tools`, `agent-mcp:schema`) call the tools directly. This mode:

- Publishes `config/agent-mcp.php` and `AGENTS.md`
- Prints `agent-mcp:call` / `agent-mcp:tools` usage examples (local in-process + remote via `AGENT_MCP_URL`)
- Activates the `agent-mcp-cli` boost skill (CLI workflow guidance)

Use this mode for one-off calls, scripts, CI pipelines, or when you do not want to register an HTTP endpoint.

### Recording the mode: `.agent-mcp.json`

The install command writes a `.agent-mcp.json` file at the project root:

```json
{
    "mode": "mcp",
    "version": 1
}
```

**Commit this file.** It records the chosen mode for the whole team, CI, and laravel-boost. When the file is absent (existing installs before v0.5.0), the package behaves as if `mode` is `mcp`, so upgrading is non-breaking.

Re-running `agent-mcp:install --mode=cli` (or `--mode=mcp`) overwrites the file. The command notes when the mode changes.

### After install: wire boost

Run `boost:install` (or `boost:update --discover`) so laravel-boost injects the active mode's skill and guideline into your agent context:

```bash
php artisan boost:install
# or, if boost is already installed:
php artisan boost:update --discover
# Sail / Herd:
vendor/bin/sail artisan boost:install
```

See [Laravel Boost integration](#laravel-boost-integration) for details.

### What is published

| File | MCP mode | CLI mode |
|------|----------|----------|
| `config/agent-mcp.php` | Yes | Yes |
| `AGENTS.md` | Yes | Yes |
| `.mcp.json.example` | Yes | Yes (harmless if unused) |

## Mandatory: set the server key

**The endpoint is fail-closed.** Every request returns `401` until `AGENT_MCP_KEY` is set to a non-empty string in your `.env`.

Generate a strong key:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Add it to `.env`:

```
AGENT_MCP_KEY=paste-generated-key-here
```

The client must send the key in every request as a standard Bearer header:

```
Authorization: Bearer <your-key>
```

The header name defaults to `Authorization`. You can override it via `AGENT_MCP_KEY_HEADER` for non-standard clients (the middleware and stdio bridge both respect the configured name).

## Read-only database access

All database access in this package goes through a read-only-hardened connection. The package enforces what it can in code:

- A SELECT-only statement validator rejects any non-SELECT SQL.
- Per-engine session hardening is applied once per connection: `PRAGMA query_only = ON` (SQLite), `SET default_transaction_read_only = on` (PostgreSQL), `SET SESSION max_execution_time = <ms>` (MySQL).
- `PDO::ATTR_EMULATE_PREPARES` is asserted `false` on every connection (emulated prepares allow stacked queries; the package refuses to run against one).

**A dedicated readonly DB user is strongly recommended as an additional defense layer.** The code-level boundary is real, but a database grant is an independent control that protects you even if a bug bypasses the application layer.

### MySQL caveat

MySQL has no per-session read-only mode for a normal user. On MySQL, the write boundary is the code layer (SELECT validator + query builder). A readonly GRANT is especially important on MySQL:

```sql
CREATE USER 'agent_readonly'@'localhost' IDENTIFIED BY 'strong-password-here';
GRANT SELECT ON your_database.* TO 'agent_readonly'@'localhost';
FLUSH PRIVILEGES;
```

Do NOT grant `FILE`, `SUPER`, `INSERT`, `UPDATE`, `DELETE`, `DROP`, or any write privilege. Keep `secure_file_priv` configured on the MySQL server.

### PostgreSQL

```sql
CREATE ROLE agent_readonly LOGIN PASSWORD 'strong-password-here';
GRANT SELECT ON ALL TABLES IN SCHEMA public TO agent_readonly;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT ON TABLES TO agent_readonly;
```

Do NOT add the role to `pg_read_server_files` (it allows arbitrary file reads via `COPY ... FROM` and `pg_read_file()`). Do NOT grant `COPY` or `lo_*` (large object) privileges. Do NOT add the role to `pg_execute_server_program`.

#### Additional grants for full DB-health visibility (PostgreSQL)

The `db_slow_queries` and `db_active_locks` tools query `pg_stat_statements` and `pg_stat_activity`/`pg_locks`. These views require elevated privileges beyond the base `SELECT` grant above. For full visibility, grant the built-in monitoring roles:

```sql
GRANT pg_monitor TO agent_readonly;
-- or, if your PostgreSQL version does not have pg_monitor (pre-10):
GRANT pg_read_all_stats TO agent_readonly;
```

`pg_monitor` covers `pg_stat_activity`, `pg_stat_replication`, `pg_stat_ssl`, and `pg_stat_gssapi`. `pg_read_all_stats` covers `pg_stat_activity` and `pg_statistic`. Without these grants, `db_active_locks` and `db_slow_queries` return partial or empty results.

**Point-in-time caveat**: `db_active_locks` reflects the lock state at the exact instant the query runs. A lock that existed before the call may be gone by the time you read the output. Use the result as a snapshot, not a continuous view.

**Privilege-dependent partial results**: if the readonly role lacks `pg_monitor`/`pg_read_all_stats`, `db_slow_queries` may return rows with `NULL` query text, and `db_active_locks` may omit rows visible only to superusers. Both tools note this in their output.

**`pg_stat_statements` must be enabled**: `db_slow_queries` on PostgreSQL detects the extension via `pg_extension` at call time and returns `{available:false, reason:"pg_stat_statements not enabled"}` when it is absent. Enable it in `postgresql.conf` (`shared_preload_libraries = 'pg_stat_statements'`) and restart; the tool then works without further config.

#### Additional grants for full DB-health visibility (MySQL)

`db_slow_queries` queries `performance_schema.events_statements_summary_by_digest`. The readonly user must have access to `performance_schema`:

```sql
GRANT SELECT ON performance_schema.* TO 'agent_readonly'@'localhost';
FLUSH PRIVILEGES;
```

Without this grant, `db_slow_queries` returns `{available:false}` on MySQL.

### SQLite

SQLite has no user/grant system. The package sets `PRAGMA query_only = ON` at connection time. For defense-in-depth, also open the database file in read-only mode via the DSN:

```php
'readonly' => [
    'driver'   => 'sqlite',
    'database' => database_path('your.sqlite'),
    'foreign_key_constraints' => true,
    'options'  => [
        \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
    ],
],
```

### Connecting to the readonly user

When you provision a readonly DB user, add a dedicated connection in `config/database.php`:

```php
'readonly' => [
    'driver'   => 'mysql',  // or 'pgsql' or 'sqlite'
    'host'     => env('DB_HOST', '127.0.0.1'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_READONLY_USERNAME', 'agent_readonly'),
    'password' => env('DB_READONLY_PASSWORD', ''),
],
```

Then point `agent-mcp.connection` at it:

```
AGENT_MCP_DB_CONNECTION=readonly
```

When `connection` is null (the default), the package clones the app's default connection into an ephemeral `agent-mcp-readonly` connection and hardens the clone. The shared default connection is never modified.

## Client setup

### HTTP transport (remote / production)

Add to your `.mcp.json` (Claude Code, Cursor, and other clients that support HTTP transport):

```json
{
    "mcpServers": {
        "agent-mcp": {
            "type": "http",
            "url": "https://your-app.com/agent-mcp",
            "headers": {
                "Authorization": "Bearer YOUR_KEY_HERE"
            }
        }
    }
}
```

Or register it with the Claude CLI (required when using Laravel Boost; see below):

```bash
claude mcp add --transport http https://your-app.com/agent-mcp --header "Authorization: Bearer YOUR_KEY_HERE"
```

### stdio bridge transport (remote bridge for Claude Desktop)

**Claude Desktop** does not natively support custom HTTP headers. Use the built-in `agent-mcp:stdio` bridge instead of the `mcp-remote` shim. The bridge is a local artisan process that reads JSON-RPC lines from stdin, forwards each one to the remote HTTP endpoint with the Bearer key, and writes the reply to stdout.

Add to your `.mcp.json`:

```json
{
    "mcpServers": {
        "agent-mcp": {
            "type": "stdio",
            "command": "php",
            "args": ["artisan", "agent-mcp:stdio"],
            "env": {
                "AGENT_MCP_URL": "https://your-app.com/agent-mcp",
                "AGENT_MCP_KEY": "YOUR_KEY_HERE"
            }
        }
    }
}
```

The `env` block is operator-set. The bridge never writes the key to stdout or stderr, and TLS verification is always on.

## CLI usage (no MCP server required)

If you only occasionally need these tools, you do not have to register an MCP server in your
agent client. The package ships three artisan commands that call the tools directly from the
shell. They honor the same per-tool enable flags, audit log, and best-effort redaction as the
HTTP endpoint.

- `php artisan agent-mcp:tools` lists the tools you can call (`--all` includes ones disabled
  in config, flagged `enabled: false`).
- `php artisan agent-mcp:schema <tool>` prints a tool's input schema.
- `php artisan agent-mcp:call <tool> [<json>]` invokes a tool. Pass arguments as a JSON object
  positionally or on STDIN; the JSON result prints to stdout (raw when piped, pretty on a
  terminal, or force raw with `--raw`); diagnostics go to stderr; the exit code is non-zero on
  a tool error.

```bash
php artisan agent-mcp:call db_schema '{"table":"users"}'
echo '{"sql":"select count(*) as c from users"}' | php artisan agent-mcp:call db_raw_select
php artisan agent-mcp:call app_about --raw | jq '.environment'
```

For a Sail or Herd project use the project's artisan form (for example
`vendor/bin/sail artisan agent-mcp:call ...`).

### Local vs remote mode

By default the commands run the tool in-process against the current application (local mode).
Set `AGENT_MCP_URL` (the remote `/agent-mcp` URL) and `AGENT_MCP_KEY` (the server key) to
forward the call to a remote endpoint instead; remote mode is auto-selected when
`AGENT_MCP_URL` is present, and `--local` / `--remote` force the choice. The key travels only
in the Authorization header, never in command output.

### CLI vs registering the MCP server

Use the CLI for one-off calls, scripts, CI, or any context where the MCP server is not
registered in the client. Register the MCP server (HTTP route or the stdio bridge above) when
you want persistent, low-latency tool access across many turns of an interactive session.

### CLI security notes

- The local CLI runs without the server-admin key: shell access to the application is the
  trust boundary. The master `agent-mcp.enabled` switch and the per-tool flags still apply.
- Sensitive tools (`config_inspect`, `db_slow_queries`, `db_active_locks`, `cache_keys`,
  `run_artisan`) are off by default. When enabled, their CLI output can land in terminal
  scrollback and shell history, which the key-guarded HTTP path does not expose. `agent-mcp:call`
  refuses to print a sensitive tool's result to a terminal unless you pass `--allow-tty`;
  piping or redirecting is always allowed.
- Do NOT add `agent-mcp:*` commands to the `run_artisan` allowlist; allowlisting the CLI itself
  would let a tool re-invoke the CLI.

## Laravel Boost integration

The package ships boost-discoverable assets that `boost:install` and `boost:update --discover` pick up automatically. Which skill and which guideline branch are active depends on the mode recorded in `.agent-mcp.json` (written by `agent-mcp:install`).

| Asset | Active in |
|-------|-----------|
| `resources/boost/skills/agent-mcp-investigation/SKILL.blade.php` | MCP mode |
| `resources/boost/skills/agent-mcp-cli/SKILL.blade.php` | CLI mode |
| `resources/boost/guidelines/core.blade.php` | Both (mode-branched internally) |

Run `boost:install` (or `boost:update --discover`) after `agent-mcp:install` to inject the active mode's skill and guideline:

```bash
php artisan boost:install
# or, if boost is already installed:
php artisan boost:update --discover
# Sail / Herd:
vendor/bin/sail artisan boost:install
```

**Boost does not auto-wire third-party MCP servers** (laravel/boost#522). The MCP binding must be done separately via `agent-mcp:install` or `claude mcp add` (see Client setup above).

### Laravel Boost version note

Full mode-filtering (only the active mode's skill is injected) requires the **boost v2.4.x line** that includes `SKILL.blade.php` support (PR #627). On older boost versions, both `SKILL.md` fallbacks are installed unfiltered (both skills active, guideline is the full superset). This is functional but not mode-tailored.

To check your installed boost version:

```bash
composer show laravel/boost | grep versions
```

If you are on a pre-v2.4.x boost and want mode-filtering, upgrade boost first:

```bash
composer require laravel/boost:^2.4
php artisan boost:update --discover
```

## Custom authentication

The default auth is a single server-admin key enforced by `KeyAuthMiddleware`. To replace it with your own auth scheme, swap the middleware in `config/agent-mcp.php`:

```php
'middleware' => [
    App\Http\Middleware\YourOwnAuthMiddleware::class,
    'throttle:agent-mcp',
],
```

Your middleware is the full auth boundary. The package does not impose any additional identity or ability check beyond what your middleware enforces.

## Config reference

After publishing, `config/agent-mcp.php` contains the following keys:

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Master switch. When false, the package is completely inert. |
| `auto_register` | `true` | When true, the service provider calls `Mcp::web()` and `Mcp::local()` at boot. Set false to wire the server manually in `routes/ai.php`. This is a convenience toggle, not a security control. |
| `key` | `null` (env `AGENT_MCP_KEY`) | Server-admin key. The endpoint is fail-closed: null or empty means every request returns 401 before any comparison runs. |
| `key_header` | `'Authorization'` (env `AGENT_MCP_KEY_HEADER`) | HTTP header the middleware reads the Bearer token from. Override for non-standard clients. |
| `route` | `'agent-mcp'` | HTTP route prefix for the MCP endpoint (`/agent-mcp`). |
| `middleware` | `[KeyAuthMiddleware::class, 'throttle:agent-mcp']` | Middleware applied to the HTTP route. Replace `KeyAuthMiddleware` to use a custom auth scheme. |
| `transports.http` | `true` | Enable the HTTP (Streamable HTTP) transport. |
| `transports.stdio` | `true` | Enable the local stdio (artisan) transport. |
| `connection` | `null` (env `AGENT_MCP_DB_CONNECTION`) | The `config/database.php` connection name for all DB access. Null falls back to the app default connection with code-enforced read-only. A dedicated readonly-grant user is strongly recommended. |
| `tools.db_schema` | `true` | Enable/disable the `db_schema` tool. |
| `tools.db_query` | `true` | Enable/disable the `db_query` tool. |
| `tools.db_raw_select` | `true` | Enable/disable the `db_raw_select` tool. |
| `tools.read_logs` | `true` | Enable/disable the `read_logs` tool. |
| `tools.run_artisan` | `false` | Enable/disable `run_artisan`. Off by default; configure `artisan.allowlist` before enabling. |
| `tools.queue_backlog` | `true` | Enable/disable `queue_backlog`. |
| `tools.queue_failed_jobs` | `true` | Enable/disable `queue_failed_jobs`. |
| `tools.horizon_status` | `true` | Enable/disable `horizon_status`. Returns `{available:false}` automatically when Horizon is not installed. |
| `tools.db_index_health` | `true` | Enable/disable `db_index_health`. |
| `tools.db_missing_fk_indexes` | `true` | Enable/disable `db_missing_fk_indexes`. |
| `tools.db_table_sizes` | `true` | Enable/disable `db_table_sizes`. |
| `tools.migrations_status` | `true` | Enable/disable `migrations_status`. |
| `tools.db_slow_queries` | `false` | Enable/disable `db_slow_queries`. Off by default; requires `pg_stat_statements` (PG) or `performance_schema` (MySQL). |
| `tools.db_active_locks` | `false` | Enable/disable `db_active_locks`. Off by default; point-in-time snapshot; requires `pg_monitor` or equivalent for full PG visibility. |
| `tools.cache_status` | `true` | Enable/disable `cache_status`. |
| `tools.cache_inspect` | `true` | Enable/disable `cache_inspect`. Raw value delivery is separately gated by `cache.allow_value_read`. |
| `tools.cache_keys` | `false` | Enable/disable `cache_keys`. Off by default; excluded session-prefix keys to avoid leaking session IDs. |
| `tools.list_routes` | `true` | Enable/disable `list_routes`. |
| `tools.inspect_route` | `true` | Enable/disable `inspect_route`. |
| `tools.app_about` | `true` | Enable/disable `app_about`. |
| `tools.schedule_list` | `true` | Enable/disable `schedule_list`. |
| `tools.event_list` | `true` | Enable/disable `event_list`. |
| `tools.storage_info` | `true` | Enable/disable `storage_info`. Disk credentials are stripped from the output; root paths are reported as-is. |
| `tools.env_keys` | `true` | Enable/disable `env_keys`. Emits key names only; values are never returned. |
| `tools.config_inspect` | `false` | Enable/disable `config_inspect`. Off by default; exposes the full config key tree. Values require explicit opt-in plus safe-list. |
| `artisan.allowlist` | `[]` | Exact command names the agent may run. Empty array means the tool is effectively off regardless of the tool flag. Substring matching and wildcards are not supported. |
| `cache.allow_value_read` | `false` | When `true`, `cache_inspect` may return a raw cached value if the key also passes the key-name block-list. Default `false` keeps raw values gated off. Set via env `AGENT_MCP_CACHE_ALLOW_VALUE_READ`. |
| `config_inspect.block_list` | (see config) | Dot-path substring tokens that unconditionally redact a config value. Defaults cover `password`, `passwd`, `secret`, `key`, `token`, `auth`, `credential`, `private`, `dsn`, `url`, `cipher`, `salt`, `cert`, `pass`, `webhook`, `client_secret`. The block-list always wins over `safe_list`. |
| `config_inspect.safe_list` | `[]` | Exact dot-paths whose values `config_inspect` is allowed to reveal when `reveal_values=true` and the path is not block-listed. Empty by default; the operator must explicitly add paths here. |
| `query.max_rows` | `100` | Upper bound on rows returned by `db_query`; auto-applied as LIMIT on `db_raw_select` queries that omit one. |
| `query.statement_timeout_ms` | `5000` | Per-statement execution cap applied at the DB session layer (MySQL: `max_execution_time`, PostgreSQL: `statement_timeout`, SQLite: `query_only` pragma). |
| `logs.channel` | `null` | Logging channel whose file `read_logs` tails. Null resolves the active default channel at runtime. |
| `logs.max_lines` | `200` | Upper bound on lines returned per `read_logs` call. |
| `redaction.enabled` | `true` | Enable best-effort output redaction. See Security model below. |
| `redaction.patterns` | (see config) | List of PCRE regexes; each match is replaced with `[REDACTED]`. Defaults cover emails, Bearer tokens, JWTs, AWS keys, credit card numbers, and password-like key/value pairs. |
| `audit.enabled` | `true` | Record tool invocations (tool name, argument shape, timestamp) to the audit channel. Argument values are never logged. |
| `audit.channel` | `'agent-mcp-audit'` | Laravel logging channel for audit entries. |

## Security model

Understanding this section is important before deploying the package to production.

### Authentication: fail-closed server key

The MCP endpoint is protected by a single server-admin key (`AGENT_MCP_KEY`). The check is fail-closed: when the key is unset or empty, every request is rejected with `401` before any comparison runs. This closes the `hash_equals('', '')` fail-open (two empty strings compare equal). The comparison is constant-time (`hash_equals`).

The key is read only from server config/env. The `agent-mcp:stdio` bridge sources it only from operator-set ENV (`AGENT_MCP_KEY` in the `.mcp.json` `env` block), never from stdin data, so a malicious peer cannot redirect the credential.

### Read-only database boundary

The read-only guarantee rests on multiple layers:

1. A SELECT-only statement validator rejects non-SELECT SQL at the application layer (defense-in-depth for `db_raw_select`).
2. Per-engine session hardening (see above) applies an engine-level read-only flag or timeout on every resolved connection.
3. On the default-connection fallback path, the package clones the default connection config into an ephemeral `agent-mcp-readonly` connection and hardens the clone only, so the app's shared default connection is never mutated.
4. A dedicated readonly DB user (strongly recommended) provides an independent, grant-level enforcement boundary that protects against application-layer bugs.

### v0.3.0 investigation tools: operator opt-in model

The twenty new investigation tools are read-only by design. A number of them expose information that may be sensitive in your environment: config values, env key names, cache data, slow-query text, active lock holders, and storage disk layout. The security model for these tools has two layers:

1. **Per-tool toggle**: every new tool is individually gated by its `tools.*` config flag. Sensitive tools (`config_inspect`, `db_slow_queries`, `db_active_locks`, `cache_keys`) default to off. Enable only the tools you actually need for your agent use case.
2. **Value gating before redaction**: for tools that can return values (primarily `config_inspect` and `cache_inspect`), value delivery is gated first by an explicit opt-in flag (`reveal_values`, `cache.allow_value_read`), then by a block-list that unconditionally redacts known-sensitive dot-paths (`url`, `dsn`, `key`, `password`, `secret`, and more). Output redaction runs after these gates as a final net, not as the primary guard. Redaction alone is never sufficient.

Optional backends (`horizon_status`, `db_slow_queries` PG path, Redis paths in `cache_keys`) are detect-then-use: the tool interrogates whether the backend is available at call time and returns `{available:false}` when it is not. No exception is thrown; no package-level hard dependency is added.

**Queue and cache table reads use the configured store connection.** The DB-health tools (`db_index_health`, `db_table_sizes`, etc.) and `db_query`/`db_raw_select` route every read through the hardened read-only connection. By contrast `queue_backlog`, `queue_failed_jobs`, `cache_inspect`, and `cache_keys` read the `jobs`/`failed_jobs`/cache tables on the connection those stores are actually configured to use (`queue.connections.*.connection`, `queue.failed.database`, `cache.stores.*.connection`), which may differ from the read-only clone. These reads use read-only query-builder methods only (no write or mutating call exists anywhere in the package), but they do not carry the per-engine read-only session flag or statement timeout that the hardened connection applies. The independent enforcement boundary here is the dedicated readonly DB user (strongly recommended above): grant-level read-only access covers these reads regardless of the application-layer connection.

### Redaction is best-effort, not a guarantee

Output redaction is enabled by default and applies to all tool responses: query results, schema output, and log lines. It replaces detected secrets with `[REDACTED]`.

**Redaction is best-effort defense-in-depth. It is NOT a security guarantee and does NOT replace the read-only grant.**

Reasons this matters:

- Legitimately stored data that matches a redaction pattern (for example, an email column) will be redacted, but that is the expected trade-off.
- Novel or obfuscated secret formats will pass through undetected.
- Data stored in a format the redaction patterns do not recognise (encoded, split across columns, etc.) will not be caught.
- An LLM can be instructed by malicious data to exfiltrate information in ways that bypass redaction.

The read-only grant is what prevents the agent from writing, deleting, or reading server files. Redaction is an additional layer to reduce accidental exposure in tool output. Do not rely on redaction as the sole protection for sensitive data.

### app.debug warning

**Never expose the MCP endpoint when `app.debug=true`.**

The package strips stack traces from MCP error responses regardless of the debug flag. However, with debug mode enabled, Laravel error pages, exception handlers, and debug toolbars can leak configuration values, stack traces, query bindings, and environment variables to any client that can reach the endpoint, including the connected LLM agent.

Set `APP_DEBUG=false` in production. If you need debug mode for local development, restrict the endpoint to localhost or an internal network.

### Supported laravel/mcp range and pre-1.0 note

This package pins `laravel/mcp` to `>=0.6 <0.8`. The `laravel/mcp` package is pre-1.0 and has breaking changes between minor versions. The tight pin is intentional: it prevents silent upgrades to an incompatible minor that could change the tool API, transport behaviour, or authentication flow.

When a new `laravel/mcp` minor is released, check the changelog before widening the constraint. Update tests against the new version before deploying.

Supported database engines: **MySQL**, **PostgreSQL**, **SQLite**. MariaDB is not officially supported: the statement timeout mechanism uses MySQL's `max_execution_time` syntax, which is incompatible with MariaDB's `max_statement_time` (different units and syntax). Using MariaDB without additional configuration may result in timeout statements being ignored silently.

## License

MIT
