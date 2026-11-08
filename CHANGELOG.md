# Changelog

All notable changes to `laravel-agent-mcp` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

This is the 2.0.0 line: 25 tools become 14, every JSON answer is compact and capped, and tool and config names change. See [Upgrading to 2.0.0](#upgrading-to-200).

### Security

- `db_raw_select` accepted a CTE whose final statement was `DELETE` or `UPDATE`: `WITH r AS (SELECT 1) DELETE FROM users` passed the SELECT-only validator. MySQL has no session-level read-only flag, so on a connection without a SELECT-only grant the statement could run; PostgreSQL (`default_transaction_read_only`) and SQLite (`query_only`) still refused it. The validator now requires the statement after the CTE terms to be one read-only SELECT. Affected: all 1.x releases up to 1.2.0. Until you upgrade, give the connection a SELECT-only grant or set `agent-mcp.tools.db_raw_select` to `false`.
- `db_raw_select` no longer loses its row cap to a trailing comment (`-- x`) and no longer appends a second `LIMIT` to a CTE that has its own. An explicit `LIMIT` above `agent-mcp.query.max_rows`, a negative one, or a placeholder is refused with a message naming the cap. A `LIMIT` counts only at the top level of the statement: one inside a parenthesised `UNION` branch bounds that branch, so the cap is still appended.
- The SELECT validator refuses SQL that the parser (a MySQL lexer) and the database could split into different tokens, because a call the name scan filed as comment or string text could then run, for example `pg_read_file` after a `#` comment, a backslash before a closing quote, a `$$` string or a `--` comment ended by a carriage return on PostgreSQL. It now rejects a backslash anywhere outside a comment, a `$` outside a string, `--` not followed by whitespace, `#` comments, `/*!` and `/*M!` comments, block comments that are unterminated or contain `/*`, and the `DELIMITER` keyword. Plain `-- note` comments and terminated `/* ... */` comments still pass. Expect a few refusals that older versions accepted: any string containing a backslash (for example `LIKE 'a\_b'`) and identifiers containing `$`.

### Removed

- Seventeen tools folded into six, with no alias for the old names: `list_routes` and `inspect_route` (now `routes`); `queue_backlog`, `queue_failed_jobs` and `horizon_status` (now `queue_status`); `db_index_health`, `db_missing_fk_indexes` and `db_table_sizes` (now `db_health`); `db_slow_queries` and `db_active_locks` (now `db_diagnostics`); `cache_status` and `cache_inspect` (now `cache`); `app_about`, `schedule_list`, `event_list`, `storage_info` and `env_keys` (now `app_info`). The table under [Upgrading to 2.0.0](#upgrading-to-200) maps each one.
- The flat `ran` list of `migrations_status`. Each migration is named once, under `batches`; `ran_count` and `latest_batch` stay.

### Changed

- Tool count is 14, of which 10 are on by default (`db_schema`, `db_query`, `db_raw_select`, `read_logs`, `migrations_status`, `db_health`, `queue_status`, `cache`, `routes`, `app_info`). `run_artisan`, `db_diagnostics`, `cache_keys` and `config_inspect` stay off.
- A tool with sections uses its first section when none is given and refuses an unknown one with the list of valid names. The audit log records the resolved `section` next to the argument shape.
- Every JSON answer is compact (no pretty-print). Answers that can grow are capped and carry `truncated: true` only when output was cut: `db_query` where lists and `db_raw_select` return `{rows, truncated?}` (`db_raw_select` used to return a bare list); `queue_status` `failed` takes `limit` (default 25, at most 100) and returns `total` and `truncated?`; `db_health` takes `limit` (default 50 tables, at most 200) with `total` and `truncated?`; `read_logs` prefixes `(truncated: showing the last N lines)` when older lines were cut.
- `agent-mcp.query.max_rows` defaults to 50 (was 100) and `agent-mcp.logs.max_lines` to 100 (was 200). `AGENT_MCP_MAX_ROWS` and `AGENT_MCP_LOG_MAX_LINES` still override them. A negative `limit` on `db_query` no longer lifts the cap, and its `query_type` is optional (default `where`).
- `app_info` `about` filters with `parts` (was `sections`).
- Every tool except `run_artisan` carries the `readOnlyHint` annotation.
- The server instructions were rewritten for Claude Code tool search and are about 1,500 characters.
- `laravel/mcp` requirement widened from `>=0.6 <0.10` to `>=0.6 <2.0`, so an application can move to `laravel/mcp` 1.x and through it to `laravel/ai` 1.x, which conflicts with `laravel/mcp <1.0`. The suite, PHPStan and Pint pass on 0.6.7, 0.9.7 and 1.1.2, and CI now runs 0.6, 0.9 and the latest 1.x. The remote CLI sends requests without `_meta`, which 1.x treats as legacy and exempts from header validation, and no code here builds a `Request` with a session id, so the 1.0 breaking changes needed only the bridge fix below.
- Dev tooling: Pest `^4.0.2||^5.0` and `pestphp/pest-plugin-laravel` `^4.0||^5.0`. Pest 5 needs PHP 8.4, so PHP 8.3 keeps resolving Pest 4.
- Dependabot no longer ignores `laravel/mcp` releases below 2.0.

### Fixed

- `db_raw_select` and `db_query` no longer fail or return `{}` on a column that holds bytes that are not valid UTF-8 (a binary UUID, a blob): the bytes are substituted and the rest of the row is kept. `db_query` refuses an unknown `query_type` instead of running a `where` query. A call whose `section` is refused is now written to the audit log.
- `agent-mcp:stdio` mirrors the request's method, tool name and `_meta` protocol version into the `Mcp-Method`, `Mcp-Name` and `MCP-Protocol-Version` headers. `laravel/mcp` 1.x rejects a non-legacy request whose headers do not match its body with error -32020, and the bridge used to send a fixed protocol version and no method header. A value that is not plain header-safe text is dropped instead of being copied from stdin into the HTTP request.
- `cache_keys` reads the configured redis client before it connects. With the `predis` client and no Predis package installed, the tool failed with an internal error instead of reporting `available: false`. Clients built on phpredis keep enumerating keys. This also clears a PHPStan finding that Larastan 3.14 raised on the old `instanceof` check.
- `cache_inspect` no longer relies on a `catch (Throwable)` around `unserialize()`, which PHPStan 2.2.16 flags as dead because it cannot see Laravel's error handler turning the parse warning into an exception. The warning is now recorded for that one call, and any payload that fails or only partly parses (for example `i:5;junk`) is reported as the string it is, as before; deprecations still reach the application's handler.
- `cache_inspect` no longer parses a cached payload that names an enum. `unserialize()` autoloads enum classes even with `allowed_classes` off, so inspecting such a key ran whatever the autoloader includes. Those values are now reported as strings.
- README tool count and write-safety wording. The package ships 25 tools: 24 that only read, and `run_artisan`, which runs nothing until an operator allowlists a command. The README had called all 25 read-only and said write tools do not exist; it now says no tool writes to the database and describes how `run_artisan` is gated.

### Upgrading to 2.0.0

Call sites change, and so do the config flags, because each flag follows its tool's new name.

| 1.x tool | 2.0 tool | Section or argument | Config flag |
|----------|----------|---------------------|-------------|
| `list_routes` | `routes` | list (no `name` or `uri`) | `routes` |
| `inspect_route` | `routes` | `name` or `uri` | `routes` |
| `queue_backlog` | `queue_status` | `backlog` (default) | `queue_status` |
| `queue_failed_jobs` | `queue_status` | `failed` | `queue_status` |
| `horizon_status` | `queue_status` | `horizon` | `queue_status` |
| `db_table_sizes` | `db_health` | `sizes` (default) | `db_health` |
| `db_index_health` | `db_health` | `indexes` | `db_health` |
| `db_missing_fk_indexes` | `db_health` | `missing_fk` | `db_health` |
| `db_slow_queries` | `db_diagnostics` | `slow_queries` (default) | `db_diagnostics` (off) |
| `db_active_locks` | `db_diagnostics` | `locks` | `db_diagnostics` (off) |
| `cache_status` | `cache` | no `key` | `cache` |
| `cache_inspect` | `cache` | `key` | `cache` |
| `app_about` | `app_info` | `about` (default) | `app_info` |
| `schedule_list` | `app_info` | `schedule` | `app_info` |
| `event_list` | `app_info` | `events` | `app_info` |
| `storage_info` | `app_info` | `storage` | `app_info` |
| `env_keys` | `app_info` | `env_keys` | `app_info` |

`db_schema`, `db_query`, `db_raw_select`, `read_logs`, `migrations_status`, `cache_keys`, `config_inspect` and `run_artisan` keep their names and flags.

One flag opens every section of its tool, so read what you are enabling:

- `db_diagnostics` opens `slow_queries` and `locks` together. On PostgreSQL it needs `pg_stat_statements` plus `pg_read_all_stats` or `pg_monitor`; on MySQL it needs `performance_schema`.
- `app_info` opens `about`, `env_keys` (names only), `storage`, `schedule` and `events`.
- `cache` opens the store status and key inspection. A value is returned only with `raw_value`, `agent-mcp.cache.allow_value_read` and a key the block-list allows.

A published `config/agent-mcp.php` replaces the whole `tools` array, because the framework merges config shallowly. After upgrading it lacks the six new keys, so those tools stay off until you add them:

```php
'tools' => [
    // ...existing keys...
    'db_health' => true,
    'queue_status' => true,
    'cache' => true,
    'routes' => true,
    'app_info' => true,
    'db_diagnostics' => false,
],
```

The retired 1.x keys are ignored. `agent-mcp:install` and `agent-mcp:tools` print one warning naming each retired key still present, the tool that replaced it and the sections that flag opens (on stderr for `agent-mcp:tools`, so its JSON stays clean); nothing is written to the application log. Other callers to update: `db_raw_select` now returns `{rows, truncated?}` instead of a bare list, `migrations_status` has no `ran` list, and `app_info` `about` takes `parts` instead of `sections`.

## 1.2.0 - 2026-09-14

### Changed

- `phpmyadmin/sql-parser` requirement moved from `^5.10` to `^6.0`. The SELECT-only validator's token scan now compares against the `PhpMyAdmin\SqlParser\TokenType` enum that 6.0 introduced in place of the `Token::TYPE_*` constants, and rejects a query whose parser produced no token list at all. The accepted and rejected shapes are unchanged: every case in the validator corpus keeps the verdict and the mechanism it had on 5.11.1.

- `laravel/mcp` requirement widened from `>=0.6 <0.8` to `>=0.6 <0.10`. A file-level diff between v0.7.2 and v0.9.5 found `Request`, `Response`, `Tool`, the three attributes, both transports and `Server::handle()` byte-identical, and v0.9.0's only "Upgrading To" items are client-side, which this package never touches. Tool-call exception handling moved into a new `ToolInvoker` in v0.8.1: it catches every `Throwable` before `StripsErrorTraces` sees it, but only produces a generic message because that trait has already forced `app.debug` off, so the trait stays load-bearing. The suite holds across 0.6.7, 0.7.2, 0.8.2 and 0.9.5.

### Added

- `server.json`, the Model Context Protocol registry manifest, with a test that keeps its version in step with the server's own `#[Version]` attribute.

### Fixed

- The server reported itself as `1.0.0` over MCP `initialize` after 1.1.0 shipped, because `#[Version]` was never bumped. A client checking whether it was running a patched build was told the version [GHSA-36q8-qrv7-frf6](https://github.com/anilcancakir/laravel-agent-mcp/security/advisories/GHSA-36q8-qrv7-frf6) lists as affected.

## 1.1.0 - 2026-09-13

### Security

- The SELECT validator accepted a forbidden identifier that was quoted, unicode-escaped, or wrapped in a function that executes its string argument. `SELECT "pg_read_file"('/etc/passwd')` passed, and the read-only connection does not contain a function that only reads. See [GHSA-36q8-qrv7-frf6](https://github.com/anilcancakir/laravel-agent-mcp/security/advisories/GHSA-36q8-qrv7-frf6) for the classes, the preconditions and the workaround. Affected: `>=0.1.0, <=1.0.0`.

  The identifier scan now covers quoted identifiers, refuses a quoted name whose value changes while being unescaped, and the name list gained the `dblink_` family, the `pg_` file and directory siblings, and the xml functions that execute a query string.

  Naming those functions closes those functions and not the class they belong to. The payload sits in a single-quoted literal, which the scan skips by design because that exclusion is what keeps an ordinary `WHERE name = 'copy'` working. The class documentation now says so: this is an allowlist on statement shape with a known-incomplete blocklist of identifier names layered inside it.

### Removed

- Laravel 11 support. `illuminate/contracts` is now `^12.0||^13.0`, `orchestra/testbench` is now `^10.0||^11.0`, and the Laravel 11 rows are gone from the CI matrix.

  Two independent reasons, either of which would be enough on its own.

  Composer cannot install Laravel 11 at all. Every `laravel/framework` 11.x release reachable from any `orchestra/testbench` 9.x, up to and including the current head v11.56.1, is withheld by three open advisories: `PKSA-m5cs-t1y6-qpcs` (`<12.61.1`), `PKSA-3r5d-mb8f-1qw9` (`<12.60.0`) and `PKSA-mdq4-51ck-6kdq` (`>=11.0.0,<12.0.0`). Each was fixed on the 12 and 13 branches and none was backported, which is what end of life looks like in practice. The alternative was to switch off Composer's advisory blocking or add the IDs to an ignore list, and that is the wrong trade for a package built to be safe to point an agent at.

  Maintaining it would mean carrying `orchestra/testbench` `^9.0` forever. Testbench tracks framework majors one to one (v9.17.0 requires `laravel/framework ^11.50.0`, v10.11.0 requires `^12.55.0`, v11.2.0 requires `^13.23.0`), so a Laravel 11 row pins the 9 line alongside the newer ones indefinitely.

  If your application is on Laravel 11, there is no version of this package you can safely run. You cannot install 1.1.0, because Composer refuses to resolve Laravel 11 at all for the reason above. And you should not stay on 1.0.0, because that is one of the versions the security advisory above covers. Set `agent-mcp.tools.db_raw_select` to `false`, which closes the affected surface, and move the application to Laravel 12 or 13.

## 1.0.0 - 2026-06-01

Initial public release. A secure, read-only Model Context Protocol (MCP) server for Laravel that gives AI coding agents safe live access to a running app.

### Added

- Read-only MCP server over HTTP (Streamable HTTP transport) and a local stdio bridge, registered via `agent-mcp:install`.
- Single server-admin Bearer key authentication (`AGENT_MCP_KEY`): fail-closed, constant-time, with no Laravel Sanctum, user model, or database table required.
- 25 read-only tools, each individually gated by `config('agent-mcp.tools.<name>')`, with sensitive tools off by default:
  - Database: `db_schema`, `db_query`, `db_raw_select` (SELECT-only validated), `db_index_health`, `db_missing_fk_indexes`, `db_table_sizes`, `migrations_status`, `db_slow_queries`, `db_active_locks`.
  - Logs and artisan: `read_logs`, `run_artisan` (exact allowlist, empty by default).
  - Queue: `queue_backlog`, `queue_failed_jobs`, `horizon_status`.
  - Cache: `cache_status`, `cache_inspect`, `cache_keys`.
  - Application introspection: `list_routes`, `inspect_route`, `app_about`, `schedule_list`, `event_list`, `storage_info`, `env_keys`, `config_inspect`.
- Defense-in-depth read-only boundary: a SELECT-only SQL grammar validator, a per-engine read-only-hardened connection (no emulated prepares, ephemeral clone fallback that never mutates the shared default connection), best-effort output redaction, and audit logging that records the tool name and argument shape but never values.
- Two install modes recorded in a committed `.agent-mcp.json`: `mcp` (default, registers the MCP server) and `cli` (artisan commands only).
- CLI surface: `agent-mcp:call`, `agent-mcp:tools`, `agent-mcp:schema` (local in-process or remote via `AGENT_MCP_URL`), plus the `agent-mcp:stdio` remote bridge for clients without custom HTTP headers.
- Committed CLI remote URL: `agent-mcp:install --url=https://your-app.example.com` persists a `url` in `.agent-mcp.json` (the CLI equivalent of `.mcp.json`) so every `agent-mcp:call` on that checkout auto-targets the remote endpoint without requiring an env variable. `AGENT_MCP_URL` env overrides the committed value; `AGENT_MCP_KEY` stays env-only and is never written to any file. The URL must be `https` (plain `http` is allowed only for loopback addresses); a configured non-loopback `http://` URL errors loudly at call time rather than silently falling back to local. Committing a URL routes the Bearer key to that host on every call: treat changes to the `url` field in `.agent-mcp.json` as credential-routing changes.
- Laravel Boost integration: mode-guarded skills and a guideline shipped under `resources/boost/`, auto-discovered by `boost:install`.
- Optional community CTAs in both skills: an opt-in, prose-permission, once-per-session "star the repo" prompt after a verified end-to-end investigation, and an "open an issue" prompt after a genuine agent-mcp-side bug, with the executable detail (exact `gh` commands, issue-body skeleton, diagnostic-gather order, label rule, URL-only fallback, and the not-bug-worthy exclusions: disabled-tool denials, absent-backend `{available:false}`, `[REDACTED]` values, capped results, and validator rejections) in each skill's `references/community.md`. Both CTAs are never auto-executed and require an explicit user yes on a visible draft before any `gh` call.
- Boost-independent injection: when laravel-boost is absent, `agent-mcp:install` writes the mode-correct guideline into the selected agents' instruction files (inside a managed, idempotent, atomically written `<laravel-agent-mcp-guidelines>` marker block) and copies the active-mode skill into each agent's skills directory. Agent selection via an interactive multiselect or `--agents=key1,key2|all` (default: detected agents, falling back to Claude Code); `--no-inject` skips, `--inject` forces even when boost is installed. Supports the 10 boost-parity agents (Claude Code, Cursor, Copilot, Junie, Gemini, Codex, OpenCode, Amp, Kiro, Antigravity); shared guideline files and skill dirs are written once.
- Configurable read-only connection, query row caps, statement timeout, redaction patterns, and audit channel.

### Supported

- PHP 8.3, 8.4, and 8.5.
- Laravel 11, 12, and 13.
- `laravel/mcp` `>=0.6 <0.8` (green on both 0.6 and 0.7).
- MySQL, PostgreSQL, and SQLite.
