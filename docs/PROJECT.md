# laravel-agent-mcp

## What this is

A Laravel package (`anilcancakir/laravel-agent-mcp`, MIT) that gives AI coding agents read-only access to a running Laravel app, over MCP (HTTP plus a stdio bridge) or an artisan CLI. Laravel developers and server operators install it, set one key, and hand the agent live schema, queries, logs, queue, cache, routes and config. Claude Code is the primary client; Cursor and other MCP clients work.

## Core value

An agent reads the live app with no path to write to it.

## Scope

### Active

- 14 tools (10 on by default) covering database, logs, queue, cache, routes, config, scheduled tasks and events
- MCP HTTP endpoint, stdio bridge for Claude Desktop, and CLI mode (`agent-mcp:call`, `agent-mcp:tools`, `agent-mcp:schema`)
- Installer that writes the guideline and the active-mode skill into each agent's files, and laravel-boost assets
- `laravel/mcp` 0.6 through 1.x on Laravel 12 and 13
- Token cost for Claude Code: small instructions, merged tools, compact capped output

### Out of scope

- Write tools: the read-only promise is the product; not planned. `run_artisan` runs only an operator allowlist.
- Per-user, Sanctum or database-backed auth: the first build modelled auth that way and was wrong; one server-admin key is the design; not planned.
- MariaDB as an officially supported engine: its statement-timeout syntax differs from MySQL's; revisit when someone supplies and tests the MariaDB path.
- Building a custom MCP server with your own domain tools: that is `laravel/mcp`; not planned.
- Server-side tool search, `Cacheable` and `alwaysLoad` tuning: deferred in 2.0; revisit when Claude Code's tool search stops covering the cost.

## Constraints

- **Safety**: no code path may write to the application database or the filesystem on an agent's behalf, other than allowlisted artisan commands. Why: an operator gives an agent the key and must be able to explain the boundary in a review.
- **Auth**: one fail-closed key (`AGENT_MCP_KEY`); an unset key rejects every request. Why: it must work on a fresh server and in CI before any user table exists.
- **`laravel/mcp` range**: `>=0.6 <2.0`. Why: `laravel/ai` 1.x conflicts with `laravel/mcp <1.0`, and a new major can change the transport, so the upper bound moves only after testing.
- **Token budget**: server instructions stay near 1,500 characters; answers are capped (50 rows, 100 log lines) and set `truncated` only when cut. Why: Claude Code loads tool names and instructions on every request.
- **Breaking changes**: only in a major version, with the old-to-new table in the changelog. Why: consumers pin tool names and config keys.

## Context

- Claude Code defers MCP tool definitions behind tool search, so tool count and instruction length matter more than definition size.
- Laravel Boost does not auto-wire third-party MCP servers (laravel/boost#522), so the installer prints the registration commands.
- Redaction is best effort; the read-only grant and the validator are the boundary.
- Known debt: the `routes` list, `app_info` events and `db_diagnostics` locks have no cap; `SELECT ... FOR UPDATE` passes the validator and takes row locks on MySQL; `InstallCommand` prints `claude mcp add` without the server name.

## Decisions

| Decision | Why | Rejected alternatives | Status |
|----------|-----|-----------------------|--------|
| 2.0 merges 25 tools into 14 | Fewer definitions and prompts for the agent to load | A non-breaking trim that kept 25 tools | Active |
| No aliases for retired tool names and config keys; a warning in `agent-mcp:install` and `agent-mcp:tools` | Aliases keep a dead surface alive | Silent aliases; a warning in the application log, which `read_logs` would tail | Active |
| One config flag per merged tool | A section of a tool is not a separate permission | One flag per section | Active |
| Output caps of 50 rows, 100 log lines, 25 failed jobs, 50 tables | Answers stay small; `truncated` tells the agent to narrow | Larger caps; no caps | Active |
| The SELECT validator refuses input the MySQL-lexer parser and the database could tokenise differently | Closes a path to `pg_read_file` and a `WITH ... DELETE` | Tokenising per engine; refusal fails closed | Active |
| Single server-admin key | Simplest boundary an operator can explain | Sanctum, a user model, a database table | Active |

## Where to look

- Architecture: ARCHITECTURE.md
- Commands and conventions: CLAUDE.md
- Shipped history and the 1.x to 2.0 table: CHANGELOG.md
- Public pitch and setup: README.md

Last reviewed: 2026-11-09 after the 2.0 merge.
