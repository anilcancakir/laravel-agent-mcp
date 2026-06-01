<?php

namespace Anilcancakir\LaravelAgentMcp\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Resolves the mandatory read-only database connection and hardens it at the
 * connection layer. This is the REAL SQL-injection boundary of the package: the SELECT-statement parser is defense-in-depth
 * layered on top, never the sole guard.
 *
 * What this class enforces directly:
 *   - PDO::ATTR_EMULATE_PREPARES === false (rejected loudly otherwise). Emulated
 *     prepares let a single bound statement carry stacked queries, which defeats
 *     parameter binding as an injection control; the package refuses to run
 *     against an emulated-prepare connection.
 *   - Per-engine session hardening applied once per resolved connection:
 *       MySQL:      SET SESSION max_execution_time = <statement_timeout_ms>
 *       PostgreSQL: SET default_transaction_read_only = on
 *                   SET statement_timeout = <statement_timeout_ms>
 *       SQLite:     PRAGMA query_only = ON
 *     SQLite has no GRANT system, so query_only plus a read-only DSN plus the
 *     statement validator are the only available enforcement.
 *     MySQL has NO per-session read-only flag for a normal user, so on MySQL the
 *     code layer (the SELECT-statement validator + the query builder) is the write
 *     boundary; a readonly GRANT is strongly recommended there.
 *
 * Connection resolution + the default-connection fallback:
 *   - When config('agent-mcp.connection') names a dedicated connection, that
 *     connection is resolved and hardened directly (the recommended setup: a
 *     dedicated readonly DB user).
 *   - When it is null/empty, the resolver falls back to the app's DEFAULT
 *     connection, but it NEVER hardens the shared default in place: PRAGMA
 *     query_only / SET ... read-only would leak to the application under Octane or
 *     any persistent/pooled connection. Instead it registers an EPHEMERAL
 *     connection (name "agent-mcp-readonly") cloned from the default's config and
 *     hardens that clone. The default-connection fallback is therefore
 *     code-enforced read-only; a dedicated readonly DB user remains recommended.
 *
 * What the CUSTOMER must satisfy on the readonly DB user (NOT enforceable here;
 * grant introspection is explicitly out of scope):
 *   - MySQL:      GRANT SELECT only. No FILE privilege (blocks LOAD_FILE /
 *                 INTO OUTFILE / INTO DUMPFILE); keep secure_file_priv set.
 *   - PostgreSQL: a SELECT-only role that is NOT a member of pg_read_server_files
 *                 (blocks pg_read_file and friends); no COPY ... TO/FROM and no
 *                 large-object (lo_*) privileges.
 *   - SQLite:     a read-only database file (read-only DSN / filesystem perms),
 *                 since SQLite cannot express per-user grants.
 *
 * The resolver enforces what it can at the connection layer and documents the
 * rest; it never probes by attempting a write.
 */
class ReadonlyConnectionResolver
{
    /**
     * Name of the ephemeral connection registered when no dedicated readonly
     * connection is configured. It is a hardened clone of the app default, kept
     * distinct so hardening never touches the shared default instance.
     */
    protected const FALLBACK_CONNECTION = 'agent-mcp-readonly';

    /**
     * Object hashes of connection instances already hardened, so the per-engine
     * session statements run once per underlying connection rather than on every
     * resolve. Laravel caches connection instances in the DatabaseManager, so the
     * same name yields the same instance within a request.
     *
     * @var array<string, true>
     */
    protected array $hardened = [];

    /**
     * Return the hardened read-only connection.
     *
     * @throws RuntimeException When the connection name is unconfigured or invalid,
     *                          or when the connection is not read-only safe.
     */
    public function connection(): Connection
    {
        $connection = $this->resolve();

        // Reject a misconfigured (emulated-prepare) connection before any query
        // can run through it. This must fail loud: the resolver does not silently
        // "fix" the attribute, it refuses the connection.
        $this->assertReadonly();

        $this->harden($connection);

        return $connection;
    }

    /**
     * Assert the resolved connection is configured for read-only safety, failing
     * loudly otherwise. Currently enforces PDO::ATTR_EMULATE_PREPARES === false.
     *
     * @throws RuntimeException When the connection name is unconfigured/invalid or
     *                          emulated prepares are enabled.
     */
    public function assertReadonly(): void
    {
        $connection = $this->resolve();

        $this->assertEmulatePreparesDisabled($connection);
    }

    /**
     * Resolve the read-only connection instance.
     *
     * When config('agent-mcp.connection') names a dedicated connection, that
     * connection is resolved. When it is null/empty, the resolver falls back to a
     * hardened ephemeral CLONE of the app default connection (never the shared
     * default instance, which must stay writable for the application).
     *
     * @throws RuntimeException When a configured name is not defined under
     *                          database.connections, or when the default-connection
     *                          fallback cannot read the default's config.
     */
    protected function resolve(): Connection
    {
        $name = config('agent-mcp.connection');

        // 1. No dedicated readonly connection configured: fall back to an ephemeral
        //    clone of the app default. The fallback never hardens the shared default
        //    in place (it would leak read-only state to the app under persistent or
        //    pooled connections), so a distinct cloned connection is the boundary.
        if (! is_string($name) || $name === '') {
            return $this->resolveDefaultFallback();
        }

        // 2. The named connection must exist. DB::connection() throws for an unknown
        //    connection; re-wrap into the package's configuration error so callers
        //    get a single, clear failure type.
        $connections = config('database.connections');

        if (! is_array($connections) || ! array_key_exists($name, $connections)) {
            throw new RuntimeException(
                "The agent-mcp readonly connection [{$name}] is not defined under "
                .'database.connections. Define a dedicated read-only connection before '
                .'using the MCP server.'
            );
        }

        return DB::connection($name);
    }

    /**
     * Register (idempotently) and resolve the ephemeral connection cloned from the
     * app default, so per-engine hardening lands on the clone and never mutates the
     * shared default connection the application uses for writes.
     *
     * @throws RuntimeException When the default connection's config cannot be read.
     */
    protected function resolveDefaultFallback(): Connection
    {
        $defaultName = config('database.default');

        $defaultConfig = config('database.connections.'.$defaultName);

        // The default connection must be defined for the clone to be meaningful;
        // fail loud rather than resolving an undefined connection later.
        if (! is_array($defaultConfig)) {
            throw new RuntimeException(
                'The agent-mcp readonly fallback could not read the default connection '
                ."[{$defaultName}] config under database.connections. Define the default "
                .'connection or set config/agent-mcp.php ("connection") to a dedicated '
                .'readonly connection.'
            );
        }

        // Register the clone under a distinct name. config()->set is idempotent here:
        // re-setting the same cloned config is harmless, and DB::connection caches the
        // resolved instance so hardening still runs once per underlying connection.
        config()->set('database.connections.'.self::FALLBACK_CONNECTION, $defaultConfig);

        return DB::connection(self::FALLBACK_CONNECTION);
    }

    /**
     * Reject the connection when emulated prepares are enabled.
     *
     * Drivers report this attribute inconsistently: MySQL and PostgreSQL expose it
     * via PDO::getAttribute, whereas SQLite's driver throws IM001 ("does not
     * support that attribute"). Both paths are handled explicitly: the configured
     * option is the authoritative signal (it is what the connector passes to PDO),
     * and the runtime attribute is checked as a second line where the driver
     * supports it.
     *
     * @throws RuntimeException When emulated prepares are enabled.
     */
    protected function assertEmulatePreparesDisabled(Connection $connection): void
    {
        $options = $connection->getConfig('options');

        // 1. Authoritative, driver-agnostic check: the connection's configured PDO
        //    options. Laravel's connector lets options override its safe default of
        //    EMULATE_PREPARES => false, so an explicit true here is the misconfig.
        if (is_array($options)
            && array_key_exists(PDO::ATTR_EMULATE_PREPARES, $options)
            && (bool) $options[PDO::ATTR_EMULATE_PREPARES] === true
        ) {
            throw $this->emulatePreparesError($connection->getName());
        }

        // 2. Second line for drivers that can report the live attribute (MySQL,
        //    PostgreSQL). SQLite throws IM001 here; that is expected and already
        //    covered by step 1, so swallow only that specific unsupported-attribute
        //    case and let any other PDO error propagate.
        try {
            $emulated = $connection->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        } catch (PDOException $e) {
            if ($this->isUnsupportedAttribute($e)) {
                return;
            }

            throw $e;
        }

        if ((bool) $emulated === true) {
            throw $this->emulatePreparesError($connection->getName());
        }
    }

    /**
     * Apply per-engine read-only session hardening exactly once per connection.
     */
    protected function harden(Connection $connection): void
    {
        $key = spl_object_hash($connection);

        if (isset($this->hardened[$key])) {
            return;
        }

        $timeoutMs = (int) config('agent-mcp.query.statement_timeout_ms', 5000);

        // Supported engines only (MySQL, PostgreSQL, SQLite). MariaDB is intentionally
        // NOT mapped to MySQL's max_execution_time: MariaDB uses max_statement_time
        // (seconds, not milliseconds), so the MySQL statement would error there. An
        // unsupported engine falls through to no session hardening rather than running
        // a statement the driver rejects; the readonly grant remains the real boundary.
        //
        // PostgreSQL also gets a per-session read-only flag so the session itself
        // refuses writes. MySQL has NO per-session read-only flag for a normal user,
        // so the timeout is all that is set at the session layer: on MySQL the SELECT
        // validator + query builder are the write boundary, and a readonly GRANT is
        // strongly recommended.
        match ($connection->getDriverName()) {
            'mysql' => $connection->statement("SET SESSION max_execution_time = {$timeoutMs}"),
            'pgsql' => $this->hardenPostgres($connection, $timeoutMs),
            'sqlite' => $connection->statement('PRAGMA query_only = ON'),
            default => null,
        };

        $this->hardened[$key] = true;
    }

    /**
     * Harden a PostgreSQL session: make the session read-only AND bound the
     * statement time. default_transaction_read_only makes the server refuse writes
     * for the session regardless of the user's grants; statement_timeout caps a
     * runaway read.
     */
    protected function hardenPostgres(Connection $connection, int $timeoutMs): void
    {
        $connection->statement('SET default_transaction_read_only = on');
        $connection->statement("SET statement_timeout = {$timeoutMs}");
    }

    /**
     * Build the loud, non-leaky configuration error for an emulated-prepare
     * connection.
     */
    protected function emulatePreparesError(string $name): RuntimeException
    {
        return new RuntimeException(
            "The agent-mcp readonly connection [{$name}] has PDO::ATTR_EMULATE_PREPARES "
            .'enabled. Emulated prepares allow stacked-query injection and are rejected; '
            .'remove the option so prepared statements are sent natively.'
        );
    }

    /**
     * Detect the SQLite "driver does not support that attribute" PDO error
     * (SQLSTATE IM001) so it can be treated as "attribute unreadable here" rather
     * than a real failure.
     */
    protected function isUnsupportedAttribute(PDOException $e): bool
    {
        return ($e->getCode() === 'IM001')
            || str_contains($e->getMessage(), 'IM001')
            || str_contains($e->getMessage(), 'does not support');
    }
}
