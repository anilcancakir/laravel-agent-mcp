# laravel-agent-mcp

A secure MCP (Model Context Protocol) server package for Laravel. It exposes a set of read-only tools that let an authenticated LLM agent inspect your database schema, run safe queries, read application logs, and invoke explicitly allowlisted artisan commands, all behind Sanctum token authentication and a mandatory read-only database connection.

## What it does

The package registers an MCP server on your Laravel application over HTTP (Streamable HTTP transport) and/or stdio. Five tools are available to the connected agent:

| Tool | What it does | Default state |
|------|-------------|---------------|
| `db_schema` | Lists tables, or returns columns, indexes, and foreign keys for a given table | Enabled |
| `db_query` | Structured query builder: find/where/count with bound parameters | Enabled |
| `db_raw_select` | Accepts a raw SQL SELECT, validates it via an allowlist parser, auto-applies a row limit, then executes on the readonly connection | Enabled |
| `read_logs` | Tails the configured log channel, with an optional level filter and output redaction | Enabled |
| `run_artisan` | Runs an artisan command from an explicit allowlist | Disabled by default; opt-in required |

Every tool call is ability-gated, audited, and run through best-effort output redaction. DB tools access only the configured readonly connection.

## Requirements

- PHP `^8.3`
- Laravel `11` or `12`
- `laravel/mcp` `>=0.7 <0.8` (pre-1.0, pinned; see [Security model](#security-model) for the pin rationale)

## Installation

```bash
composer require anilcancakir/laravel-agent-mcp
```

Run the install command to publish the config and agent assets, and to get ready-to-paste client config blocks printed to your terminal:

```bash
php artisan agent-mcp:install
```

This publishes:
- `config/agent-mcp.php` (all configuration knobs, documented inline)
- `AGENTS.md` (tool-usage guidance for the connected agent)
- `.mcp.json.example` (ready-to-adapt client config)

## Mandatory: readonly database connection

**This is the most important setup step.** All database access in this package goes through a single named connection (default: `readonly`). That connection MUST be backed by a SELECT-only database user at the grant level. The package enforces what it can at the connection layer (statement timeouts, `PRAGMA query_only` for SQLite), but privilege isolation at the database level is the real enforcement boundary.

Add the connection to `config/database.php` under the `connections` key, pointing at the same host/database as your default connection but using the readonly user credentials:

```php
'readonly' => [
    'driver'   => 'mysql', // or 'pgsql' or 'sqlite'
    'host'     => env('DB_HOST', '127.0.0.1'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_READONLY_USERNAME', 'agent_readonly'),
    'password' => env('DB_READONLY_PASSWORD', ''),
    // ... rest of driver-specific options
],
```

Then provision the user in your database engine using the recipes below.

### MySQL

```sql
-- Create the user.
CREATE USER 'agent_readonly'@'localhost' IDENTIFIED BY 'strong-password-here';

-- Grant SELECT on your database only. No FILE, no SUPER, no write privileges.
GRANT SELECT ON your_database.* TO 'agent_readonly'@'localhost';

FLUSH PRIVILEGES;
```

Important notes for MySQL:

- Do NOT grant `FILE`, `SUPER`, `INSERT`, `UPDATE`, `DELETE`, `DROP`, or any write privilege.
- Ensure `secure_file_priv` is configured on the MySQL server to restrict file access. Even without the `FILE` privilege on this user, defense-in-depth at the server level matters.
- The package sets `SET SESSION max_execution_time = <ms>` on the connection to enforce the configured statement timeout.

### PostgreSQL

```sql
-- Create a login role with no write permissions.
CREATE ROLE agent_readonly LOGIN PASSWORD 'strong-password-here';

-- Grant SELECT on all current tables.
GRANT SELECT ON ALL TABLES IN SCHEMA public TO agent_readonly;

-- Grant SELECT on future tables automatically.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT ON TABLES TO agent_readonly;
```

Important notes for PostgreSQL:

- Do NOT add the role to `pg_read_server_files`. That role allows reading arbitrary server-side files via `COPY ... FROM` and `pg_read_file()`.
- Do NOT grant `COPY` or `lo_*` (large object) privileges.
- Do NOT add the role to `pg_execute_server_program`.
- The package sets `SET statement_timeout = <ms>` on the connection.

### SQLite

SQLite has no user/grant system. Enforcement relies on two layers:

1. Open the database file in read-only mode via the DSN (`mode=ro`):

```php
'readonly' => [
    'driver'   => 'sqlite',
    'database' => database_path('your.sqlite'),
    'foreign_key_constraints' => true,
    'options'  => [
        // PDO SQLite read-only open flag.
        \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
    ],
],
```

2. The package sets `PRAGMA query_only = ON` on the connection at resolution time, which prevents any write statement from executing even if the file permissions were somehow broader.

Note: SQLite support is provided for local development and testing. For production, MySQL or PostgreSQL with proper grant isolation is strongly recommended.

## Sanctum token and abilities

The MCP HTTP endpoint uses `auth:sanctum` middleware. Every request must carry a Sanctum personal access token in the `Authorization: Bearer <token>` header.

Create a token with the `agent-mcp:read` ability:

```php
$token = $user->createToken('agent-mcp', ['agent-mcp:read']);
echo $token->plainTextToken;
```

If you enable `run_artisan`, also include `agent-mcp:artisan`:

```php
$token = $user->createToken('agent-mcp', ['agent-mcp:read', 'agent-mcp:artisan']);
```

**Scope the token tightly.** The token ability is part of the authorization boundary. A token with unneeded abilities widens the attack surface. Create a dedicated token for the agent, do not reuse a general-purpose token.

## Client setup

### HTTP transport (remote / production)

```json
{
    "mcpServers": {
        "agent-mcp": {
            "type": "http",
            "url": "https://your-app.com/mcp",
            "headers": {
                "Authorization": "Bearer <your-token>"
            }
        }
    }
}
```

**Claude Desktop** does not natively support custom HTTP headers. Use the `mcp-remote` shim to forward the `Authorization` header: [https://github.com/geelen/mcp-remote](https://github.com/geelen/mcp-remote)

**Claude Code** and **Cursor** support the `headers` field directly in `.mcp.json`.

### stdio transport (local / same machine)

When the client runs on the same machine as the Laravel app, stdio avoids a network hop entirely. No token is needed because the process is spawned by the client under the app's own filesystem permissions.

```json
{
    "mcpServers": {
        "agent-mcp": {
            "type": "stdio",
            "command": "php",
            "args": ["artisan", "mcp:start", "agent-mcp"]
        }
    }
}
```

The artisan command for the stdio transport is `php artisan mcp:start agent-mcp`. Ensure the working directory is your Laravel project root, or use an absolute path to the `artisan` binary.

## Config reference

After publishing, `config/agent-mcp.php` contains the following keys:

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Master switch. When false, the package is completely inert. |
| `auto_register` | `true` | When true, the service provider calls `Mcp::web()` and `Mcp::local()` at boot. Set false to wire the server manually in `routes/ai.php`. This is a convenience toggle, not a security control. |
| `route` | `'mcp'` | The HTTP route prefix for the MCP endpoint (`/mcp`). |
| `middleware` | `['auth:sanctum', 'throttle:agent-mcp']` | Middleware applied to the HTTP route. Do not remove `auth:sanctum`. |
| `transports.http` | `true` | Enable the HTTP (Streamable HTTP) transport. |
| `transports.stdio` | `true` | Enable the stdio (local artisan) transport. |
| `connection` | `'readonly'` | The `config/database.php` connection name used for all DB access. MUST be a SELECT-only user. |
| `abilities.read` | `'agent-mcp:read'` | Sanctum ability required for `db_schema`, `db_query`, `db_raw_select`, `read_logs`. |
| `abilities.artisan` | `'agent-mcp:artisan'` | Sanctum ability required for `run_artisan`. |
| `tools.db_schema` | `true` | Enable/disable the `db_schema` tool. |
| `tools.db_query` | `true` | Enable/disable the `db_query` tool. |
| `tools.db_raw_select` | `true` | Enable/disable the `db_raw_select` tool. |
| `tools.read_logs` | `true` | Enable/disable the `read_logs` tool. |
| `tools.run_artisan` | `false` | Enable/disable `run_artisan`. Off by default; configure `artisan.allowlist` before enabling. |
| `artisan.allowlist` | `[]` | Exact command names the agent may run. Empty array means the tool is effectively off regardless of the tool flag. Substring matching and wildcards are not supported. |
| `query.max_rows` | `100` | Upper bound on rows returned by `db_query`; auto-applied as `LIMIT` on `db_raw_select` queries that omit one. |
| `query.statement_timeout_ms` | `5000` | Per-statement execution cap applied to the readonly connection at the DB session layer (MySQL: `max_execution_time`, PostgreSQL: `statement_timeout`, SQLite: `query_only` pragma). |
| `logs.channel` | `null` | Logging channel whose file `read_logs` tails. `null` resolves the active default channel at runtime. |
| `logs.max_lines` | `200` | Upper bound on lines returned per `read_logs` call. |
| `redaction.enabled` | `true` | Enable best-effort output redaction. See Security model below. |
| `redaction.patterns` | (see config) | List of PCRE regexes; each match is replaced with `[REDACTED]`. Defaults cover emails, Bearer tokens, JWTs, AWS keys, credit card numbers, and password-like key/value pairs. |
| `audit.enabled` | `true` | Record tool invocations (tool name, argument shape, caller identity, timestamp) to the audit channel. Argument values are never logged. |
| `audit.channel` | `'agent-mcp-audit'` | Laravel logging channel for audit entries. |

## Security model

Understanding this section is important before deploying the package to production.

### The real boundary: grant + abilities + token scoping

The security of this package rests on three layers that you control:

1. **The readonly database grant.** The database user behind the `readonly` connection must have SELECT-only privileges. This is enforced at the database engine level, not by the package. No amount of clever PHP code substitutes for a proper database grant. The package enforces what it can (statement timeouts, `PRAGMA query_only` on SQLite, `EMULATE_PREPARES=false` to prevent stacked queries), but grant isolation is the authoritative boundary.

2. **Sanctum ability scoping.** Every tool call checks `tokenCan()` inside `handle()`. The check is authoritative: it runs even if the tool visibility check (`shouldRegister`) is bypassed. Scope your tokens to the minimum needed abilities. A read-only agent should have `agent-mcp:read` only.

3. **The human who scopes the token.** The token represents a user in your system. That user's trust level determines what the agent can observe. Create a dedicated user or a minimal-permission account for agent tokens. Do not hand the agent the credentials of an admin user.

### Redaction is best-effort, not a guarantee

Output redaction is enabled by default and applies to all tool responses: query results, schema output, and log lines. It replaces detected secrets (emails, tokens, API keys, credit card numbers, password-like pairs) with `[REDACTED]`.

**Redaction is best-effort defense-in-depth. It is NOT a security guarantee and does NOT replace the readonly grant.**

Reasons this matters:

- Legitimately stored data that matches a redaction pattern (for example, an email column) will be redacted, but that is the expected trade-off.
- Novel or obfuscated secret formats will pass through undetected.
- Data stored in a format the redaction patterns do not recognise (encoded, split across columns, etc.) will not be caught.
- An LLM can be instructed by malicious data to exfiltrate information in ways that bypass redaction.

The readonly grant is what prevents the agent from writing, deleting, or reading server files. Redaction is an additional layer to reduce accidental exposure in tool output. Do not rely on redaction as the sole protection for sensitive data.

### app.debug warning

**Never expose the MCP endpoint when `app.debug=true`.**

The package strips stack traces from MCP error responses regardless of the debug flag. However, with debug mode enabled, Laravel error pages, exception handlers, and debug toolbars can leak configuration values, stack traces, query bindings, and environment variables to any client that can reach the endpoint, including the connected LLM agent.

Set `APP_DEBUG=false` in production. If you need debug mode for local development, restrict the endpoint to localhost or an internal network.

### Supported laravel/mcp range and pre-1.0 note

This package pins `laravel/mcp` to `>=0.7 <0.8`. The `laravel/mcp` package is pre-1.0 and has breaking changes between minor versions. The tight pin is intentional: it prevents silent upgrades to an incompatible minor that could change the tool API, transport behaviour, or authentication flow.

When a new `laravel/mcp` minor is released, check the changelog before widening the constraint. Update tests against the new version before deploying.

Supported database engines: **MySQL**, **PostgreSQL**, **SQLite**. MariaDB is not officially supported: the statement timeout mechanism uses MySQL's `max_execution_time` syntax, which is incompatible with MariaDB's `max_statement_time` (different units and syntax). Using MariaDB without additional configuration may result in timeout statements being ignored silently.

## License

MIT
