<?php

declare(strict_types=1);

namespace PhpDb\Async\Pgsql;

use Override;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\Adapter\Exception\InvalidQueryException;
use PhpDb\Adapter\Exception\RuntimeException;
use PhpDb\Pgsql\Connection as BaseConnection;

use function extension_loaded;
use function pg_connection_busy;
use function pg_get_result;
use function pg_last_error;
use function pg_send_query;
use function sprintf;

use function Async\suspend;

/**
 * Non-blocking PostgreSQL connection for TrueAsync environments.
 *
 * Overrides execute() to use pg_send_query() + a pg_connection_busy() polling
 * loop with Async\suspend() between iterations. Each suspend() yields the
 * current coroutine to the TrueAsync scheduler, allowing other coroutines
 * (including other HTTP requests) to run while this connection waits for the
 * PostgreSQL server to respond.
 *
 * When ext-true_async is not loaded, the behaviour falls back to the
 * synchronous parent implementation (pg_query) so the class remains safe to
 * instantiate in non-async contexts (e.g., test environments).
 *
 * Requires: PHP ext-pgsql, ext-true_async (PHP 8.6+) for non-blocking path.
 *
 * Phase-2 note: pg_send_prepare() + pg_send_execute() override for the
 * prepared-statement path (Statement::execute()) would require an async
 * Statement subclass and async Driver subclass to wire it. That is left as a
 * planned extension.
 */
class Connection extends BaseConnection
{
    /**
     * Execute $sql against the server, yielding the coroutine to the
     * TrueAsync scheduler while waiting for the response.
     *
     * {@inheritDoc}
     */
    #[Override]
    public function execute($sql): ResultInterface
    {
        if (! $this->isConnected()) {
            $this->connect();
        }

        // Without the extension use the synchronous parent path transparently.
        if (! extension_loaded('true_async')) {
            return parent::execute($sql);
        }

        $this->profiler?->profilerStart($sql);

        if (pg_send_query($this->resource, $sql) === false) {
            throw new RuntimeException(sprintf(
                '%s: Unable to send async query: %s',
                __METHOD__,
                pg_last_error($this->resource),
            ));
        }

        // Yield to the scheduler while the server is still processing.
        // Each suspend() allows other coroutines to run for one scheduler tick.
        while (pg_connection_busy($this->resource)) {
            suspend();
        }

        $resultResource = pg_get_result($this->resource);

        $this->profiler?->profilerFinish();

        if ($resultResource === false) {
            throw new InvalidQueryException(pg_last_error($this->resource));
        }

        /** @phpstan-ignore argument.type */
        return $this->driver->createResult($resultResource);
    }
}
