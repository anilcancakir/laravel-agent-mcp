## Agent MCP Tools

This application exposes read-only investigation tools (database, logs, queue, cache, routes, config) over MCP at the `/agent-mcp` endpoint, authenticated by a single server-admin key (`AGENT_MCP_KEY`); there is no user login. Every tool is read-only with no write surface, so ground answers in live runtime state and never assume a write will succeed. Some tools are off by default (`config_inspect`, `db_slow_queries`, `db_active_locks`, `cache_keys`); treat a denial as the expected boundary. Output may be redacted as a best-effort secret filter.

When using the MCP server: before any query call `db_schema` to learn the real tables and columns. Prefer `db_query` for structured reads and use `db_raw_select` only for SELECTs the builder cannot express. On a 500 or failed job, read `read_logs` first. The `agent-mcp-investigation` skill carries the full workflow and the per-tool reference.

When using the CLI: call the tools with `php artisan agent-mcp:call <tool> '<json>'`, using `agent-mcp:tools` and `agent-mcp:schema` to discover them; they run locally or against a remote app via `AGENT_MCP_URL`. The `agent-mcp-cli` skill carries the full CLI workflow.
