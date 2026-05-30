<?php

use Anilcancakir\LaravelAgentMcp\Http\Middleware\KeyAuthMiddleware;

return [

    /*
    |--------------------------------------------------------------------------
    | Package enabled flag
    |--------------------------------------------------------------------------
    |
    | Master switch. When false the service provider skips all registration so
    | the package is installed but completely inert.
    |
    */

    'enabled' => env('AGENT_MCP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Auto-register routes at boot
    |--------------------------------------------------------------------------
    |
    | When true (default) the service provider calls Mcp::web() and Mcp::local()
    | at boot so no customer route file edit is required. Set to false when you
    | want to wire the server manually in routes/ai.php instead. This is a
    | convenience toggle, NOT a security control.
    |
    */

    'auto_register' => env('AGENT_MCP_AUTO_REGISTER', true),

    /*
    |--------------------------------------------------------------------------
    | Server-admin authentication key
    |--------------------------------------------------------------------------
    |
    | A strong random value the operator sets via AGENT_MCP_KEY. The server is
    | FAIL-CLOSED: if this is null or empty every request returns 401 before any
    | compare runs. Generate a key with: php -r "echo bin2hex(random_bytes(32));"
    |
    */

    'key' => env('AGENT_MCP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Key header name
    |--------------------------------------------------------------------------
    |
    | The HTTP request header the middleware reads the Bearer token from.
    | Defaults to Authorization, which means standard "Authorization: Bearer <key>"
    | semantics. Override via AGENT_MCP_KEY_HEADER for non-standard clients.
    |
    */

    'key_header' => env('AGENT_MCP_KEY_HEADER', 'Authorization'),

    /*
    |--------------------------------------------------------------------------
    | HTTP route prefix
    |--------------------------------------------------------------------------
    */

    'route' => 'agent-mcp',

    /*
    |--------------------------------------------------------------------------
    | Route middleware
    |--------------------------------------------------------------------------
    |
    | Applied to the HTTP MCP route. KeyAuthMiddleware enforces the server-admin
    | key (fail-closed) before the MCP layer is reached. The throttle limiter is
    | defined in the service provider at boot, keyed by a fingerprint of the
    | presented key rather than the caller IP.
    |
    */

    'middleware' => [
        KeyAuthMiddleware::class,
        'throttle:agent-mcp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Transport flags
    |--------------------------------------------------------------------------
    |
    | Independent switches for each transport. Set http to false to disable the
    | web endpoint; set stdio to false to disable the local CLI transport.
    |
    */

    'transports' => [
        'http' => true,
        'stdio' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Read-only database connection
    |--------------------------------------------------------------------------
    |
    | The connection name this package uses for all DB access. When null the
    | resolver falls back to the app default connection and enforces read-only at
    | the code layer (SELECT validator + per-engine session pragma). A dedicated
    | readonly-grant DB user is strongly recommended for defense-in-depth,
    | especially on MySQL where no per-session read-only exists for normal users.
    |
    */

    'connection' => env('AGENT_MCP_DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Tool enable flags
    |--------------------------------------------------------------------------
    |
    | Per-tool on/off switches evaluated at boot (shouldRegister) and enforced
    | authoritatively in handle(). Disabling a tool here hides it from the MCP
    | tool list and causes handle() to return a denial before doing any work.
    |
    | run_artisan is OFF by default: executing artisan commands is a high-risk
    | surface (confused-deputy, destructive commands). Enable it deliberately
    | and configure artisan.allowlist before relying on it.
    |
    */

    'tools' => [
        'db_schema' => true,
        'db_query' => true,
        'db_raw_select' => true,
        'read_logs' => true,

        // Disabled by default: requires explicit allowlist configuration.
        // An empty allowlist with this flag true is also safe (handle() denies),
        // but the default-off state signals intent clearly to the operator.
        'run_artisan' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Artisan command allowlist
    |--------------------------------------------------------------------------
    |
    | Empty array (default) means the run_artisan tool is effectively off even
    | if the tool flag is toggled on. Populate with exact command names the agent
    | is allowed to call. Substring matching and wildcards are NOT supported:
    | only exact names pass. Each entry may optionally declare permitted options.
    |
    | Example:
    |   'allowlist' => [
    |       'route:list',
    |       ['command' => 'cache:clear', 'options' => []],
    |   ],
    |
    */

    'artisan' => [
        'allowlist' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Query constraints
    |--------------------------------------------------------------------------
    |
    | max_rows: upper bound on rows returned by db_query and auto-appended LIMIT
    | on db_raw_select queries that omit one.
    |
    | statement_timeout_ms: per-statement execution cap applied to the readonly
    | connection at the DB session layer (MySQL max_execution_time, PostgreSQL
    | statement_timeout, SQLite query_only pragma). Mitigates DoS via expensive
    | or long-running agent-issued SELECTs.
    |
    */

    'query' => [
        'max_rows' => (int) env('AGENT_MCP_MAX_ROWS', 100),
        'statement_timeout_ms' => (int) env('AGENT_MCP_STATEMENT_TIMEOUT_MS', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log reading
    |--------------------------------------------------------------------------
    |
    | channel: the logging channel whose file the read_logs tool tails. null
    | means "resolve the active default channel at runtime."
    |
    | max_lines: upper bound on lines returned per call.
    |
    */

    'logs' => [
        'channel' => env('AGENT_MCP_LOG_CHANNEL'),
        'max_lines' => (int) env('AGENT_MCP_LOG_MAX_LINES', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output redaction (best-effort defense-in-depth)
    |--------------------------------------------------------------------------
    |
    | Enabled by default as a best-effort layer to reduce accidental exposure of
    | secrets that appear in query results or log lines. This is NOT a security
    | guarantee: legitimately-stored data that looks like a secret will be
    | redacted, and novel secret formats will pass through undetected. The real
    | security boundary is the readonly DB grant and the SELECT validator.
    |
    | patterns: list of PCRE regexes. Each match is replaced with [REDACTED].
    |
    */

    'redaction' => [
        'enabled' => (bool) env('AGENT_MCP_REDACTION_ENABLED', true),
        'patterns' => [
            // Email addresses.
            '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',

            // Bearer tokens and JWT (three base64url segments separated by dots).
            '/Bearer\s+[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+/',
            '/[A-Za-z0-9\-_]{20,}\.[A-Za-z0-9\-_]{20,}\.[A-Za-z0-9\-_]{20,}/',

            // AWS access key IDs and secret access keys.
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/\b[A-Za-z0-9\/+]{40}\b/',

            // Credit card numbers (13-19 consecutive digits, common grouping patterns).
            '/\b(?:\d[ \-]?){13,19}\b/',

            // Password-like key=value pairs (password, secret, token, key, api_key, etc.).
            '/(?:password|passwd|secret|api[_\-]?key|token|auth)["\s]*[:=]["\s]*[^\s"\'&,;]{6,}/i',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit logging
    |--------------------------------------------------------------------------
    |
    | Records tool invocations (tool name, argument shape, timestamp) to a
    | dedicated log channel. Argument VALUES are never logged; only the shape
    | (key names + value types) is captured to preserve auditability without
    | re-creating a secondary data store of agent-read data.
    |
    | Enabled by default: operators need visibility into what the agent is doing
    | against their production database before they can trust it.
    |
    */

    'audit' => [
        'enabled' => (bool) env('AGENT_MCP_AUDIT_ENABLED', true),
        'channel' => env('AGENT_MCP_AUDIT_CHANNEL', 'agent-mcp-audit'),
    ],

];
