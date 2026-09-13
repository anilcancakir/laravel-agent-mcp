# Changelog

All notable changes to `laravel-agent-mcp` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Removed

- Laravel 11 support. `illuminate/contracts` is now `^12.0||^13.0`, `orchestra/testbench` is now `^10.0||^11.0`, and the Laravel 11 rows are gone from the CI matrix.

  Two independent reasons, either of which would be enough on its own.

  Composer cannot install Laravel 11 at all. Every `laravel/framework` 11.x release reachable from any `orchestra/testbench` 9.x, up to and including the current head v11.56.1, is withheld by three open advisories: `PKSA-m5cs-t1y6-qpcs` (`<12.61.1`), `PKSA-3r5d-mb8f-1qw9` (`<12.60.0`) and `PKSA-mdq4-51ck-6kdq` (`>=11.0.0,<12.0.0`). Each was fixed on the 12 and 13 branches and none was backported, which is what end of life looks like in practice. The alternative was to switch off Composer's advisory blocking or add the IDs to an ignore list, and that is the wrong trade for a package built to be safe to point an agent at.

  Maintaining it would mean carrying `orchestra/testbench` `^9.0` forever. Testbench tracks framework majors one to one (v9.17.0 requires `laravel/framework ^11.50.0`, v10.11.0 requires `^12.55.0`, v11.2.0 requires `^13.23.0`), so a Laravel 11 row pins the 9 line alongside the newer ones indefinitely.

  If your application is on Laravel 11, stay on v1.0.0 until you upgrade.

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
