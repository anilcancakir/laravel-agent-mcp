## Agent MCP Tools

This application serves a read-only investigation server at `/agent-mcp`, authenticated by one server-admin key (`AGENT_MCP_KEY`), not a user login. No tool writes to the database, so ground answers in live runtime state and surface a change you want made instead of attempting it. Some tools are off by default (`run_artisan`, `db_diagnostics`, `cache_keys`, `config_inspect`); a denial is the expected boundary, so do not work around it. Output is redacted as a best-effort filter, so a `[REDACTED]` value is no proof the data is absent.

@if(\Anilcancakir\LaravelAgentMcp\Support\InstallMode::current() === 'cli')
Call the tools from the shell with `php artisan agent-mcp:call <tool> '<json>'`, and discover them with `agent-mcp:tools` and `agent-mcp:schema`. With a remote URL configured (`url` in `.agent-mcp.json` via `agent-mcp:install --url`, or the `AGENT_MCP_URL` env override) the call goes to the remote app over https with `AGENT_MCP_KEY` as a Bearer token. For the workflow, flags and per-tool detail, use the `agent-mcp-cli` skill.
@else
Call `db_schema` before any query, because the live schema can differ from the models. Use `db_query` for reads and `db_raw_select` only for joins or aggregates it cannot express. On a 500 or a failed job, read `read_logs` before proposing a fix. For the investigation workflow and per-tool detail, use the `agent-mcp-investigation` skill.
@endif
