# agent-mcp CLI command reference

All commands live under the `agent-mcp:` namespace and honor the master
`config('agent-mcp.enabled')` switch and the per-tool `config('agent-mcp.tools.*')` flags.
Run them with the project's artisan binary (`php artisan ...`, or `vendor/bin/sail artisan ...`
for Sail). Output goes to stdout (the tool payload) and stderr (diagnostics); the exit code
is 0 on success and non-zero on any error.

## Mode selection (all commands)

- Default: local (in-process against the current app).
- Auto-remote: when `AGENT_MCP_URL` is set in the environment, the command forwards to that
  remote `/agent-mcp` endpoint using `AGENT_MCP_KEY` as a Bearer token.
- `--local`: force in-process execution.
- `--remote`: force remote forwarding (requires `AGENT_MCP_URL` + `AGENT_MCP_KEY`).

## agent-mcp:call

Invoke a single tool and print its result.

```
php artisan agent-mcp:call <tool> [<input>] [--remote] [--local] [--allow-tty] [--raw]
```

- `<tool>`: the tool name (for example `db_schema`, `db_query`, `read_logs`).
- `<input>`: a JSON object of arguments. Omit it to read the JSON from STDIN. An empty source
  means no arguments (`{}`).
- `--allow-tty`: required to print a sensitive tool's result to a terminal (see below).
- `--raw`: emit the raw payload without pretty-printing (pretty-printing otherwise applies
  only on a terminal).

Examples:

```
php artisan agent-mcp:call db_schema
php artisan agent-mcp:call db_schema '{"table":"users"}'
echo '{"sql":"select count(*) as c from users"}' | php artisan agent-mcp:call db_raw_select
php artisan agent-mcp:call app_about --raw | jq '.environment'
```

Exit code: non-zero on a disabled/unknown tool, malformed JSON, a sensitive-tool tty refusal,
or a remote transport error.

## agent-mcp:tools

List the tools available via the CLI as a JSON array of `{name, description, enabled}`.

```
php artisan agent-mcp:tools [--all] [--remote] [--local]
```

- Default: only enabled tools (those whose `config('agent-mcp.tools.<name>')` is true).
- `--all`: also include disabled tools, each flagged `"enabled": false`.

## agent-mcp:schema

Print a tool's input schema as JSON (`{name, description, inputSchema}`). Does not invoke it.

```
php artisan agent-mcp:schema <tool> [--remote] [--local]
```

## Sensitive tools and the terminal

These tools are off by default and can surface secrets:
`run_artisan`, `config_inspect`, `db_slow_queries`, `db_active_locks`, `cache_keys`.

When one of them is enabled, `agent-mcp:call` refuses to write its result to an interactive
terminal unless `--allow-tty` is passed, because the output would land in terminal scrollback
and shell history. Piping or redirecting the output (the usual agent and CI path) is always
allowed:

```
php artisan agent-mcp:call config_inspect '{"key":"app"}' | jq .        # allowed (piped)
php artisan agent-mcp:call config_inspect '{"key":"app"}' --allow-tty   # explicit terminal opt-in
```
