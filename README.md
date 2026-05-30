# laravel-agent-mcp

A secure MCP (Model Context Protocol) server package for Laravel. It exposes a set of read-only tools that let an authenticated LLM agent inspect your database schema, run safe queries, read application logs, and invoke explicitly allowlisted artisan commands, all behind a server-admin key and a code-enforced read-only database boundary.

## What it does

The package registers an MCP server on your Laravel application over HTTP (Streamable HTTP transport) and/or a local stdio bridge. Five tools are available to the connected agent:

| Tool | What it does | Default state |
|------|-------------|---------------|
| `db_schema` | Lists tables, or returns columns, indexes, and foreign keys for a given table | Enabled |
| `db_query` | Structured query builder: find/where/count with bound parameters | Enabled |
| `db_raw_select` | Accepts a raw SQL SELECT, validates it via an allowlist parser, auto-applies a row limit, then executes on the read-only connection | Enabled |
| `read_logs` | Tails the configured log channel, with an optional level filter and output redaction | Enabled |
| `run_artisan` | Runs an artisan command from an explicit allowlist | Disabled by default; opt-in required |

Every tool call is audited and run through best-effort output redaction. DB tools access a read-only connection (code-enforced; a dedicated readonly DB user is strongly recommended).

## Requirements

- PHP `^8.3` (PHP 8.2 is untested: Pest 4, PHPUnit 12, and Testbench 11 all require ^8.3)
- Laravel `11`, `12`, or `13`
- `laravel/mcp` `>=0.6 <0.8` (pre-1.0, tightly pinned; see [Security model](#security-model) for the rationale)

## Version matrix

| PHP | Laravel | Supported |
|-----|---------|-----------|
| 8.3 | 11, 12, 13 | Yes |
| 8.4 | 11, 12, 13 | Yes |
| 8.5 | 11, 12, 13 | Yes |
| 8.2 | any | Untested (toolchain requires ^8.3) |

## Installation

```bash
composer require anilcancakir/laravel-agent-mcp
```

Run the install command to publish the config and agent assets and to get ready-to-paste client config blocks printed to your terminal:

```bash
php artisan agent-mcp:install
```

This publishes:
- `config/agent-mcp.php` (all configuration knobs, documented inline)
- `AGENTS.md` (tool-usage guidance for the connected agent)
- `.mcp.json.example` (ready-to-adapt client config)

## Mandatory: set the server key

**The endpoint is fail-closed.** Every request returns `401` until `AGENT_MCP_KEY` is set to a non-empty string in your `.env`.

Generate a strong key:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Add it to `.env`:

```
AGENT_MCP_KEY=paste-generated-key-here
```

The client must send the key in every request as a standard Bearer header:

```
Authorization: Bearer <your-key>
```

The header name defaults to `Authorization`. You can override it via `AGENT_MCP_KEY_HEADER` for non-standard clients (the middleware and stdio bridge both respect the configured name).

## Read-only database access

All database access in this package goes through a read-only-hardened connection. The package enforces what it can in code:

- A SELECT-only statement validator rejects any non-SELECT SQL.
- Per-engine session hardening is applied once per connection: `PRAGMA query_only = ON` (SQLite), `SET default_transaction_read_only = on` (PostgreSQL), `SET SESSION max_execution_time = <ms>` (MySQL).
- `PDO::ATTR_EMULATE_PREPARES` is asserted `false` on every connection (emulated prepares allow stacked queries; the package refuses to run against one).

**A dedicated readonly DB user is strongly recommended as an additional defense layer.** The code-level boundary is real, but a database grant is an independent control that protects you even if a bug bypasses the application layer.

### MySQL caveat

MySQL has no per-session read-only mode for a normal user. On MySQL, the write boundary is the code layer (SELECT validator + query builder). A readonly GRANT is especially important on MySQL:

```sql
CREATE USER 'agent_readonly'@'localhost' IDENTIFIED BY 'strong-password-here';
GRANT SELECT ON your_database.* TO 'agent_readonly'@'localhost';
FLUSH PRIVILEGES;
```

Do NOT grant `FILE`, `SUPER`, `INSERT`, `UPDATE`, `DELETE`, `DROP`, or any write privilege. Keep `secure_file_priv` configured on the MySQL server.

### PostgreSQL

```sql
CREATE ROLE agent_readonly LOGIN PASSWORD 'strong-password-here';
GRANT SELECT ON ALL TABLES IN SCHEMA public TO agent_readonly;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT ON TABLES TO agent_readonly;
```

Do NOT add the role to `pg_read_server_files` (it allows arbitrary file reads via `COPY ... FROM` and `pg_read_file()`). Do NOT grant `COPY` or `lo_*` (large object) privileges. Do NOT add the role to `pg_execute_server_program`.

### SQLite

SQLite has no user/grant system. The package sets `PRAGMA query_only = ON` at connection time. For defense-in-depth, also open the database file in read-only mode via the DSN:

```php
'readonly' => [
    'driver'   => 'sqlite',
    'database' => database_path('your.sqlite'),
    'foreign_key_constraints' => true,
    'options'  => [
        \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
    ],
],
```

### Connecting to the readonly user

When you provision a readonly DB user, add a dedicated connection in `config/database.php`:

```php
'readonly' => [
    'driver'   => 'mysql',  // or 'pgsql' or 'sqlite'
    'host'     => env('DB_HOST', '127.0.0.1'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_READONLY_USERNAME', 'agent_readonly'),
    'password' => env('DB_READONLY_PASSWORD', ''),
],
```

Then point `agent-mcp.connection` at it:

```
AGENT_MCP_DB_CONNECTION=readonly
```

When `connection` is null (the default), the package clones the app's default connection into an ephemeral `agent-mcp-readonly` connection and hardens the clone. The shared default connection is never modified.

## Client setup

### HTTP transport (remote / production)

Add to your `.mcp.json` (Claude Code, Cursor, and other clients that support HTTP transport):

```json
{
    "mcpServers": {
        "agent-mcp": {
            "type": "http",
            "url": "https://your-app.com/agent-mcp",
            "headers": {
                "Authorization": "Bearer YOUR_KEY_HERE"
            }
        }
    }
}
```

Or register it with the Claude CLI (required when using Laravel Boost; see below):

```bash
claude mcp add --transport http https://your-app.com/agent-mcp --header "Authorization: Bearer YOUR_KEY_HERE"
```

### stdio bridge transport (remote bridge for Claude Desktop)

**Claude Desktop** does not natively support custom HTTP headers. Use the built-in `agent-mcp:stdio` bridge instead of the `mcp-remote` shim. The bridge is a local artisan process that reads JSON-RPC lines from stdin, forwards each one to the remote HTTP endpoint with the Bearer key, and writes the reply to stdout.

Add to your `.mcp.json`:

```json
{
    "mcpServers": {
        "agent-mcp": {
            "type": "stdio",
            "command": "php",
            "args": ["artisan", "agent-mcp:stdio"],
            "env": {
                "AGENT_MCP_URL": "https://your-app.com/agent-mcp",
                "AGENT_MCP_KEY": "YOUR_KEY_HERE"
            }
        }
    }
}
```

The `env` block is operator-set. The bridge never writes the key to stdout or stderr, and TLS verification is always on.

## Laravel Boost integration

The package ships boost-discoverable assets that `boost:install` and `boost:update --discover` pick up automatically:

- `resources/boost/guidelines/core.blade.php`: an AI guideline explaining when and how to use each of the 5 MCP tools, the read-only model, and the `/agent-mcp` endpoint.
- `resources/boost/skills/agent-mcp-investigation/SKILL.md`: an investigation workflow skill that walks an agent through schema inspection, read-only queries, log reading, and optional allowlisted artisan commands.

Run `boost:install` (or `boost:update --discover`) to pick these up:

```bash
php artisan boost:install
# or, if boost is already installed:
php artisan boost:update --discover
```

**Boost does not auto-wire third-party MCP servers** (laravel/boost#522). The MCP binding must be done separately via `agent-mcp:install` or `claude mcp add` (see Client setup above).

## Custom authentication

The default auth is a single server-admin key enforced by `KeyAuthMiddleware`. To replace it with your own auth scheme, swap the middleware in `config/agent-mcp.php`:

```php
'middleware' => [
    App\Http\Middleware\YourOwnAuthMiddleware::class,
    'throttle:agent-mcp',
],
```

Your middleware is the full auth boundary. The package does not impose any additional identity or ability check beyond what your middleware enforces.

## Config reference

After publishing, `config/agent-mcp.php` contains the following keys:

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Master switch. When false, the package is completely inert. |
| `auto_register` | `true` | When true, the service provider calls `Mcp::web()` and `Mcp::local()` at boot. Set false to wire the server manually in `routes/ai.php`. This is a convenience toggle, not a security control. |
| `key` | `null` (env `AGENT_MCP_KEY`) | Server-admin key. The endpoint is fail-closed: null or empty means every request returns 401 before any comparison runs. |
| `key_header` | `'Authorization'` (env `AGENT_MCP_KEY_HEADER`) | HTTP header the middleware reads the Bearer token from. Override for non-standard clients. |
| `route` | `'agent-mcp'` | HTTP route prefix for the MCP endpoint (`/agent-mcp`). |
| `middleware` | `[KeyAuthMiddleware::class, 'throttle:agent-mcp']` | Middleware applied to the HTTP route. Replace `KeyAuthMiddleware` to use a custom auth scheme. |
| `transports.http` | `true` | Enable the HTTP (Streamable HTTP) transport. |
| `transports.stdio` | `true` | Enable the local stdio (artisan) transport. |
| `connection` | `null` (env `AGENT_MCP_DB_CONNECTION`) | The `config/database.php` connection name for all DB access. Null falls back to the app default connection with code-enforced read-only. A dedicated readonly-grant user is strongly recommended. |
| `tools.db_schema` | `true` | Enable/disable the `db_schema` tool. |
| `tools.db_query` | `true` | Enable/disable the `db_query` tool. |
| `tools.db_raw_select` | `true` | Enable/disable the `db_raw_select` tool. |
| `tools.read_logs` | `true` | Enable/disable the `read_logs` tool. |
| `tools.run_artisan` | `false` | Enable/disable `run_artisan`. Off by default; configure `artisan.allowlist` before enabling. |
| `artisan.allowlist` | `[]` | Exact command names the agent may run. Empty array means the tool is effectively off regardless of the tool flag. Substring matching and wildcards are not supported. |
| `query.max_rows` | `100` | Upper bound on rows returned by `db_query`; auto-applied as LIMIT on `db_raw_select` queries that omit one. |
| `query.statement_timeout_ms` | `5000` | Per-statement execution cap applied at the DB session layer (MySQL: `max_execution_time`, PostgreSQL: `statement_timeout`, SQLite: `query_only` pragma). |
| `logs.channel` | `null` | Logging channel whose file `read_logs` tails. Null resolves the active default channel at runtime. |
| `logs.max_lines` | `200` | Upper bound on lines returned per `read_logs` call. |
| `redaction.enabled` | `true` | Enable best-effort output redaction. See Security model below. |
| `redaction.patterns` | (see config) | List of PCRE regexes; each match is replaced with `[REDACTED]`. Defaults cover emails, Bearer tokens, JWTs, AWS keys, credit card numbers, and password-like key/value pairs. |
| `audit.enabled` | `true` | Record tool invocations (tool name, argument shape, timestamp) to the audit channel. Argument values are never logged. |
| `audit.channel` | `'agent-mcp-audit'` | Laravel logging channel for audit entries. |

## Security model

Understanding this section is important before deploying the package to production.

### Authentication: fail-closed server key

The MCP endpoint is protected by a single server-admin key (`AGENT_MCP_KEY`). The check is fail-closed: when the key is unset or empty, every request is rejected with `401` before any comparison runs. This closes the `hash_equals('', '')` fail-open (two empty strings compare equal). The comparison is constant-time (`hash_equals`).

The key is read only from server config/env. The `agent-mcp:stdio` bridge sources it only from operator-set ENV (`AGENT_MCP_KEY` in the `.mcp.json` `env` block), never from stdin data, so a malicious peer cannot redirect the credential.

### Read-only database boundary

The read-only guarantee rests on multiple layers:

1. A SELECT-only statement validator rejects non-SELECT SQL at the application layer (defense-in-depth for `db_raw_select`).
2. Per-engine session hardening (see above) applies an engine-level read-only flag or timeout on every resolved connection.
3. On the default-connection fallback path, the package clones the default connection config into an ephemeral `agent-mcp-readonly` connection and hardens the clone only, so the app's shared default connection is never mutated.
4. A dedicated readonly DB user (strongly recommended) provides an independent, grant-level enforcement boundary that protects against application-layer bugs.

### Redaction is best-effort, not a guarantee

Output redaction is enabled by default and applies to all tool responses: query results, schema output, and log lines. It replaces detected secrets with `[REDACTED]`.

**Redaction is best-effort defense-in-depth. It is NOT a security guarantee and does NOT replace the read-only grant.**

Reasons this matters:

- Legitimately stored data that matches a redaction pattern (for example, an email column) will be redacted, but that is the expected trade-off.
- Novel or obfuscated secret formats will pass through undetected.
- Data stored in a format the redaction patterns do not recognise (encoded, split across columns, etc.) will not be caught.
- An LLM can be instructed by malicious data to exfiltrate information in ways that bypass redaction.

The read-only grant is what prevents the agent from writing, deleting, or reading server files. Redaction is an additional layer to reduce accidental exposure in tool output. Do not rely on redaction as the sole protection for sensitive data.

### app.debug warning

**Never expose the MCP endpoint when `app.debug=true`.**

The package strips stack traces from MCP error responses regardless of the debug flag. However, with debug mode enabled, Laravel error pages, exception handlers, and debug toolbars can leak configuration values, stack traces, query bindings, and environment variables to any client that can reach the endpoint, including the connected LLM agent.

Set `APP_DEBUG=false` in production. If you need debug mode for local development, restrict the endpoint to localhost or an internal network.

### Supported laravel/mcp range and pre-1.0 note

This package pins `laravel/mcp` to `>=0.6 <0.8`. The `laravel/mcp` package is pre-1.0 and has breaking changes between minor versions. The tight pin is intentional: it prevents silent upgrades to an incompatible minor that could change the tool API, transport behaviour, or authentication flow.

When a new `laravel/mcp` minor is released, check the changelog before widening the constraint. Update tests against the new version before deploying.

Supported database engines: **MySQL**, **PostgreSQL**, **SQLite**. MariaDB is not officially supported: the statement timeout mechanism uses MySQL's `max_execution_time` syntax, which is incompatible with MariaDB's `max_statement_time` (different units and syntax). Using MariaDB without additional configuration may result in timeout statements being ignored silently.

## License

MIT
