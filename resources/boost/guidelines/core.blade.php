# Agent MCP Tools

This application exposes read-only investigation tools over the Model Context
Protocol at the `/agent-mcp` HTTP endpoint. Access is controlled by a single
server-admin key: the operator sets `AGENT_MCP_KEY` and clients send
`Authorization: Bearer <key>`. There is no user login and no per-user token; the
key grants the full read surface. Every tool is read-only and has no write
access to the database, so they are safe to call as often as you need.

Use these tools to ground answers in the live application state instead of
guessing from model code, migrations, or memory. The running database and logs
are the source of truth; code on disk may not match what is deployed.

## The five tools and when to reach for each

- `db_schema`: call this BEFORE writing any query. With no arguments it returns
  the table list; pass a `table` argument for that table's columns, types,
  indexes, and foreign keys. Call it again whenever you hit an unknown table or
  column. Do not infer schema from models or migrations.
- `db_query`: structured, parameterized reads when you know the table and the
  filter (find by id, filter by a column, count rows). Table and column names
  are validated against the live schema and values are always bound. Prefer this
  for routine lookups.
- `db_raw_select`: ad-hoc or complex SELECT supplied as raw SQL (JOINs,
  subqueries, aggregations, window functions). A SELECT-only grammar guard
  rejects anything that is not a single read-only SELECT, and a row limit is
  applied automatically. Use it only when the query builder cannot express the
  query.
- `read_logs`: on any 500, exception, failed job, or unexpected behavior, call
  this IMMEDIATELY before attempting a fix. It returns recent application log
  lines; control volume with `lines` and narrow with `level` (error, warning,
  info, debug). A best-effort secret redaction pass runs on the output.
- `run_artisan`: disabled by default. It is available only when the operator has
  explicitly enabled it and configured an exact-match command allowlist. Do not
  assume it is available; it is the highest-risk tool, so treat a denial as
  expected rather than an error to work around.

## Working habits

- Open `db_schema` at the start of any database task, not only after a query
  fails. Knowing the real shape up front prevents wrong queries.
- Reach for `read_logs` proactively when a user reports odd behavior, even
  without an explicit error: warnings and deprecations often explain it.
- Prefer `db_query` for simple lookups and keep `db_raw_select` for queries the
  builder cannot express.
