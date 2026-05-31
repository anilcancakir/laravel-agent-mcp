---
name: agent-mcp-cli
description: "Use to call the agent-mcp read-only tools from the shell with artisan, without registering an MCP server: run agent-mcp:tools to list tools, agent-mcp:schema <tool> to see its inputs, and agent-mcp:call <tool> '<json>' to invoke one, locally or against a remote app via AGENT_MCP_URL. Use for one-off calls, scripts, CI, when the agent-mcp MCP server is not registered in the client, or when deciding between a one-off CLI call and registering the server. Covers local vs remote mode, JSON input, stdout and stderr, exit codes, and --allow-tty for sensitive tools. Do NOT use for write operations; these tools are read-only. When the MCP server is registered in the client, follow the agent-mcp-investigation workflow instead."
license: MIT
metadata:
  author: anilcancakir
---
# Agent MCP CLI

Call the agent-mcp read-only tools straight from the shell, without registering an MCP server in the client. The CLI honors the same per-tool config flags, audit log, and best-effort redaction as the HTTP endpoint. Run commands with the project's artisan binary: examples use `php artisan`, but use the project's form where it differs (for example `vendor/bin/sail artisan agent-mcp:call ...`).

## CLI or MCP server

- Use the CLI for a one-off call, a script, a CI job, or any context where the agent-mcp server is not registered in the client.
- Register the MCP server (the HTTP route or the stdio bridge) when you want persistent, low-latency tool access across many turns. The thesis: do not add an MCP server you only occasionally need; reach for the CLI instead, and register the server when the need is constant.

## Workflow

1. Discover: `php artisan agent-mcp:tools` lists the callable tools (add `--all` to include tools disabled in config, flagged `enabled: false`).
2. Inspect inputs: `php artisan agent-mcp:schema <tool>` prints a tool's input schema.
3. Call: pass a JSON arguments object as a positional argument or on STDIN.
   - `php artisan agent-mcp:call db_schema '{"table":"users"}'`
   - `echo '{"table":"users"}' | php artisan agent-mcp:call db_schema`
4. Read the result: the JSON payload goes to stdout (pretty on a terminal, raw when piped, or force raw with `--raw`); diagnostics go to stderr; the exit code is non-zero on a tool error. Pipe to a processor: `php artisan agent-mcp:call db_schema --raw | jq .`.

## Local and remote

- Local (default): the command runs the tool in-process against the current app. Use it from inside the project or on the server.
- Remote: set `AGENT_MCP_URL` (the remote `/agent-mcp` URL) and `AGENT_MCP_KEY` (the server key) and the command forwards there instead. Remote mode is auto-selected when `AGENT_MCP_URL` is set; `--local` and `--remote` force the choice. The key travels only in the Authorization header, never in output.

## Security notes

- These tools are read-only. Surface a change you want made rather than attempting a write.
- Sensitive tools (`config_inspect`, `db_slow_queries`, `db_active_locks`, `cache_keys`, `run_artisan`) are off by default. When enabled, their output can land in terminal scrollback and shell history, so `agent-mcp:call` refuses to print a sensitive tool's result to a terminal unless you pass `--allow-tty`; piping or redirecting is always allowed.
- Do not add `agent-mcp:*` commands to the `run_artisan` allowlist, which would let a tool re-invoke the CLI.
- Redaction is best-effort, not a guarantee; the real boundary is the read-only database grant and the per-tool enable flags.

## Reference

For the full command and flag reference, read `references/commands.md`. For what each tool does and returns, the agent-mcp-investigation skill carries the tool-by-tool detail.
