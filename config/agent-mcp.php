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
        // --- v0.2.0 tools (unchanged) ---
        'db_schema' => true,
        'db_query' => true,
        'db_raw_select' => true,
        'read_logs' => true,

        // Disabled by default: requires explicit allowlist configuration.
        // An empty allowlist with this flag true is also safe (handle() denies),
        // but the default-off state signals intent clearly to the operator.
        'run_artisan' => false,

        // --- v0.3.0 investigation tools ---

        // Queue: queue sizes + failed-job summaries are safe read-only reads.
        'queue_backlog' => true,
        'queue_failed_jobs' => true,

        // Horizon: availability-gated; inert when Horizon is not installed.
        'horizon_status' => true,

        // Database health: catalog reads over the readonly connection.
        'db_index_health' => true,
        'db_missing_fk_indexes' => true,
        'db_table_sizes' => true,
        'migrations_status' => true,

        // Privileged DB tools: require pg_monitor/pg_read_all_stats (PostgreSQL)
        // or performance_schema access (MySQL). Disabled by default so the
        // operator explicitly grants the necessary DB privileges before enabling.
        'db_slow_queries' => false,
        'db_active_locks' => false,

        // Cache: metadata reads (status, key inspection) are safe by default.
        'cache_status' => true,
        'cache_inspect' => true,

        // cache_keys can expose live session IDs when the session and cache share
        // a Redis store. Disabled by default; enable only after verifying the
        // session prefix exclusion covers your deployment.
        'cache_keys' => false,

        // App introspection: read-only framework-level reflection.
        'list_routes' => true,
        'inspect_route' => true,
        'app_about' => true,
        'schedule_list' => true,
        'event_list' => true,
        'storage_info' => true,
        'env_keys' => true,

        // config_inspect returns arbitrary application config values and can
        // expose secrets not covered by the block_list. Disabled by default;
        // review block_list and safe_list before enabling.
        'config_inspect' => false,
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
    | Cache inspection settings
    |--------------------------------------------------------------------------
    |
    | allow_value_read: when false (default) the cache_inspect tool returns only
    | metadata (TTL, type) and never the raw cached value. Set to true AND add
    | an explicit safe_list entry via config_inspect to allow value reads on
    | selected keys. Raw values may contain serialized objects or secrets; this
    | flag exists so the operator acknowledges that risk before opting in.
    |
    */

    'cache' => [
        'allow_value_read' => (bool) env('AGENT_MCP_CACHE_ALLOW_VALUE_READ', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Config inspection access control
    |--------------------------------------------------------------------------
    |
    | block_list: case-insensitive substring tokens matched against the full
    | dot-path of a config leaf. Any path that contains one of these tokens is
    | redacted to [REDACTED] even when the caller passes reveal_values=true.
    | Includes DSN/URL tokens because database/redis connection strings embed
    | credentials (user:pass@host). This list is the primary secret gate;
    | OutputRedactor is the last net only.
    |
    | safe_list: dot-paths the operator has explicitly reviewed and deems safe
    | to expose. A leaf value is returned only when reveal_values=true AND the
    | full dot-path is in safe_list AND the path is NOT matched by block_list.
    | Default is empty so no values are revealed until the operator opts in.
    |
    */

    'config_inspect' => [
        'block_list' => [
            'password',
            'passwd',
            'secret',
            'key',
            'token',
            'auth',
            'credential',
            'private',
            'dsn',
            'url',
            'cipher',
            'salt',
            'cert',
            'pass',
            'webhook',
            'client_secret',
        ],
        'safe_list' => [],
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
