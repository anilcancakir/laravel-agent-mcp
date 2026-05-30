---
name: agent-mcp-investigation
description: "Use when investigating a running Laravel application's database, runtime state, or errors through the agent-mcp read-only MCP tools. Triggers on inspecting database schema, running read-only queries to find or count records, reading application logs to diagnose a 500, exception, or failed job, and running an operator-allowlisted artisan command. Use whenever the answer depends on live data rather than source code, even when the user does not name the tools. Do NOT trigger for write operations (inserts, updates, deletes, migrations, schema changes) or for projects without the agent-mcp endpoint."
license: MIT
metadata:
  author: anilcancakir
---
# Agent MCP Investigation

Investigate a live Laravel application through the read-only MCP tools served at
`/agent-mcp`. Authentication is a single server-admin key (`AGENT_MCP_KEY`,
presented as `Authorization: Bearer <key>`): there is no user login and no
per-user token. Every tool is read-only with no database write access, so call
them freely. The live database and logs are the source of truth; code on disk
may not match what is deployed.

## Workflow

### 1. Inspect the schema first

Call `db_schema` before writing any query. With no arguments it lists the
tables; pass a `table` argument for that table's columns, types, indexes, and
foreign keys. Confirm the real shape here rather than inferring it from models
or migrations, which may lag the deployed database. Re-check whenever you meet
an unknown table or column.

### 2. Query read-only

Pick the narrower tool that does the job:

- `db_query` for structured lookups where you know the table and filters (find
  by id, filter by a column, count rows). Table and column names are validated
  against the live schema and values are bound as parameters. Prefer this for
  routine reads.
- `db_raw_select` for queries the builder cannot express: JOINs, subqueries,
  aggregations, window functions. Supply a single SELECT statement. A
  SELECT-only grammar guard rejects anything that is not a read-only SELECT, and
  a row limit is applied automatically.

### 3. Read logs on any failure

On a 500, exception, failed job, or behavior you cannot explain, call
`read_logs` immediately, before attempting a fix. The log context is usually the
fastest path to the root cause. Control volume with `lines` and narrow with
`level` (error, warning, info, debug). Output passes through best-effort secret
redaction, so treat it as a diagnostic aid rather than a guarantee that no
sensitive value appears.

### 4. Run an allowlisted artisan command (only if enabled)

`run_artisan` is disabled by default. It runs only when the operator has enabled
it and configured an exact-match command allowlist; options are authorized
explicitly, with no wildcards. Do not assume it is available. If a command is
denied, that is the expected boundary, not an error to route around.

## Verification

1. Before querying, confirm the table and columns exist via `db_schema`.
2. After a read, sanity-check the row count and shape against what the schema
   implied.
3. When diagnosing a failure, confirm the log line you found actually matches
   the reported symptom (timestamp, level, message) before proposing a cause.

## Common pitfalls

- Inferring schema from model or migration files instead of calling `db_schema`
  against the live database.
- Reaching for `db_raw_select` when `db_query` would express the lookup more
  safely.
- Proposing a fix for an error before reading the logs with `read_logs`.
- Assuming `run_artisan` is available; it is off unless the operator enabled it.
- Expecting write access: these tools only read. Surface any change you want the
  operator to make rather than attempting it here.
