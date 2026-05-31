# Changelog

All notable changes to `laravel-agent-mcp` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 - 2026-05-31

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
- Laravel Boost integration: mode-guarded skills and a guideline shipped under `resources/boost/`, auto-discovered by `boost:install`.
- Configurable read-only connection, query row caps, statement timeout, redaction patterns, and audit channel.

### Supported

- PHP 8.3, 8.4, and 8.5.
- Laravel 11, 12, and 13.
- `laravel/mcp` `>=0.6 <0.8` (green on both 0.6 and 0.7).
- MySQL, PostgreSQL, and SQLite.
