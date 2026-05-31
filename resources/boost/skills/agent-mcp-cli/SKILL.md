---
name: agent-mcp-cli
description: "Use to call the agent-mcp read-only investigation tools from the shell via artisan, without registering an MCP server. Activates when you need to inspect a Laravel app's database schema, run a read-only query, read logs, check queue or cache or routes via the CLI; when the agent-mcp MCP server is NOT registered in the client; when scripting or running in CI; or when deciding between a one-off CLI call and registering the MCP server. Covers the agent-mcp:call, agent-mcp:tools, and agent-mcp:schema commands and local vs remote (AGENT_MCP_URL) modes. Do NOT use for write operations; these tools are read-only."
license: MIT
metadata:
  author: anilcancakir
---
# Agent MCP CLI

Call the agent-mcp read-only tools straight from the shell, without registering an MCP
server in your client. Every tool is read-only and gated by the same per-tool config flags
as the HTTP endpoint; the CLI inherits that gate, the audit log, and best-effort redaction.

Run the commands with the project's artisan binary. Examples below use `php artisan`; for a
Sail or Herd project use the project's configured form instead (for example
`vendor/bin/sail artisan agent-mcp:call ...`). Do not assume a single hardcoded binary.

## When to use the CLI vs registering the MCP server

Use the CLI (`agent-mcp:call`) when:

- The agent-mcp MCP server is NOT registered in the client (`.mcp.json` / settings).
- You need a one-off call and do not want to add a persistent MCP server.
- You are in a script, a CI job, or any context without a live MCP connection.

Register the MCP server (HTTP route or the stdio bridge) when:

- You want persistent, low-latency tool access across many turns of an interactive session.
- You will call many tools repeatedly and want them in the client's tool list.

The thesis: do not add yet another MCP server you only occasionally need; reach for the CLI
when the need is occasional, and register the server when it is constant.

## Setup

- Local mode (default): nothing to configure. The command runs the tool in-process against
  the current application. Use this from inside the project directory or on the server.
- Remote mode: set two environment variables so the command forwards to a remote agent-mcp
  HTTP endpoint instead of running locally:
  - `AGENT_MCP_URL` = the remote `/agent-mcp` URL.
  - `AGENT_MCP_KEY` = the server-admin key.
  When `AGENT_MCP_URL` is set the command auto-selects remote mode; `--local` / `--remote`
  force the choice. The key travels only in the Authorization header, never in output.

## Usage

- List the tools you can call: `php artisan agent-mcp:tools` (add `--all` to include tools
  that are disabled in config, flagged `enabled: false`).
- See a tool's input shape: `php artisan agent-mcp:schema db_schema`.
- Call a tool with a JSON arguments object, as a positional argument or on STDIN:
  - `php artisan agent-mcp:call db_schema '{"table":"users"}'`
  - `echo '{"table":"users"}' | php artisan agent-mcp:call db_schema`
- The tool's JSON result prints to stdout (pretty on a terminal, raw when piped, or force raw
  with `--raw`); diagnostics print to stderr; the exit code is non-zero on a tool error.
  Pipe the output to a JSON processor: `php artisan agent-mcp:call db_schema --raw | jq .`.

## Security notes

- These tools are read-only; surface any change you want made rather than attempting a write.
- Sensitive tools (`config_inspect`, `db_slow_queries`, `db_active_locks`, `cache_keys`,
  `run_artisan`) are off by default. When enabled, their CLI output can expose data to your
  terminal scrollback and shell history, which the key-guarded HTTP path does not. The CLI
  refuses to print a sensitive tool's result to a terminal unless you pass `--allow-tty`;
  piping or redirecting the output is always allowed.
- Do NOT add `agent-mcp:*` commands to the `run_artisan` allowlist; allowlisting the CLI
  itself would let a tool re-invoke the CLI.
- Redaction is best-effort, not a guarantee. The real boundary is the read-only database
  grant and the per-tool enable flags.

## Reference

The full command and flag reference is in `references/commands.md`.
