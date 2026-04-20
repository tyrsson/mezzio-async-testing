<?php

declare(strict_types=1);

namespace PhpDb\Async\Container;

use PhpDb\Adapter\Adapter as CoreAdapter;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Async\Pgsql\Connection;
use PhpDb\Pgsql\AdapterPlatform;
use PhpDb\Pgsql\Driver;
use PhpDb\Pgsql\Result;
use PhpDb\Pgsql\Statement;
use PgSql\Connection as PgSqlConnection;

use function pg_connection_status;

use const PGSQL_CONNECTION_OK;

/**
 * Manages the lifecycle of pool slots (raw AdapterInterface instances).
 *
 * Each method maps to one Async\Pool constructor callback:
 *
 *   factory       →  $manager          (__invoke creates a new slot)
 *   destructor    →  [$manager, 'destroy']
 *   healthcheck   →  [$manager, 'isHealthy']
 *   beforeRelease →  [$manager, 'isReleasable']
 *
 * Array-callable syntax is used throughout to avoid Closure objects.
 *
 * Every slot is backed by a PhpDb\Async\Pgsql\Connection which uses
 * pg_send_query() + Async\suspend() polling instead of the blocking
 * pg_query(), yielding the coroutine to the TrueAsync scheduler while
 * waiting for the PostgreSQL server to respond.
 */
final class ConnectionManager
{
    public function __construct(
        private readonly array $connectionConfig,
    ) {}

    /**
     * Pool factory: builds one independent adapter + connection per slot.
     * Called by Async\Pool whenever a new slot needs to be created.
     */
    public function __invoke(): AdapterInterface
    {
        $connection = new Connection($this->connectionConfig);
        $driver     = new Driver(
            connection:         $connection,
            statementPrototype: new Statement(),
            resultPrototype:    new Result(),
        );
        $platform = new AdapterPlatform($driver);

        // Connect eagerly so the platform has a live PgSqlConnection resource
        // available for pg_escape_string() inside quoteValue().
        $connection->connect();

        return new CoreAdapter($driver, $platform);
    }

    /**
     * Pool destructor: cleanly closes the connection when a slot is evicted.
     */
    public function destroy(AdapterInterface $adapter): void
    {
        $adapter->getDriver()->getConnection()->disconnect();
    }

    /**
     * Pool healthcheck: verifies the underlying pg socket is still alive.
     *
     * pg_connection_status() performs a local, non-blocking status check
     * (no network round-trip). Called periodically by the pool on idle slots.
     */
    public function isHealthy(AdapterInterface $adapter): bool
    {
        $connection = $adapter->getDriver()->getConnection();

        if (! $connection->isConnected()) {
            return false;
        }

        $resource = $connection->getResource();

        if (! $resource instanceof PgSqlConnection) {
            return false;
        }

        return pg_connection_status($resource) === PGSQL_CONNECTION_OK;
    }

    /**
     * Pool beforeRelease: prevents returning a mid-transaction connection.
     *
     * If a coroutine acquired a slot, opened a transaction, and then threw
     * without rolling back, the slot is poisoned. Returning false here causes
     * the pool to destroy and recreate the slot instead of recycling it.
     */
    public function isReleasable(AdapterInterface $adapter): bool
    {
        return ! $adapter->getDriver()->getConnection()->inTransaction();
    }
}
