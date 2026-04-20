<?php

declare(strict_types=1);

namespace PhpDb\Async;

use Async\Pool;
use Override;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\DriverInterface;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\Adapter\Driver\StatementInterface;
use PhpDb\Adapter\ParameterContainer;
use PhpDb\Adapter\Platform\PlatformInterface;
use PhpDb\Adapter\Profiler\ProfilerInterface;
use PhpDb\ResultSet\ResultSetInterface;
use Throwable;

/**
 * Pool-backed AdapterInterface implementation for TrueAsync environments.
 *
 * The Async\Pool holds N independent AdapterInterface instances (each with its
 * own live connection). When query() or createStatement() is called, a slot is
 * acquired from the pool, used, then released — allowing multiple coroutines to
 * run concurrent queries against different connections with no interference.
 *
 * The $primary adapter is a single persistent adapter used only for metadata
 * calls (getDriver, getPlatform, etc.) and for sequential operations that go
 * through TableGateway / prepared-statement paths. It is NOT used for concurrent
 * reads. For maximum concurrency, call query() with QUERY_MODE_EXECUTE directly.
 *
 * Requires: ext-true_async (PHP 8.6+)
 */
final class Adapter implements AdapterInterface
{
    public function __construct(
        private readonly Pool $pool,
        private readonly AdapterInterface $primary,
    ) {}

    public function getPool(): Pool
    {
        return $this->pool;
    }

    /**
     * Execute or prepare a SQL statement using a pooled connection.
     *
     * QUERY_MODE_EXECUTE  — acquires a pool slot, executes, releases immediately
     *                       (PgSqlResult is buffered; the connection is free at once).
     * QUERY_MODE_PREPARE  — acquires a pool slot, prepares (and optionally executes),
     *                       then either releases immediately (when params were supplied)
     *                       or wraps the returned statement in Statement so the slot is
     *                       released after the caller calls execute().
     */
    #[Override]
    public function query(
        string $sql,
        ParameterContainer|array|string $parametersOrQueryMode = self::QUERY_MODE_PREPARE,
        ?ResultSetInterface $resultPrototype = null,
    ): StatementInterface|ResultSetInterface|ResultInterface {
        $inner = $this->pool->acquire();

        try {
            $result = $inner->query($sql, $parametersOrQueryMode, $resultPrototype);
        } catch (Throwable $e) {
            $this->pool->release($inner);
            throw $e;
        }

        if ($result instanceof StatementInterface) {
            // Prepared but not yet executed; the slot is released after execute().
            return new Statement($result, $this->pool, $inner);
        }

        $this->pool->release($inner);
        return $result;
    }

    /**
     * Acquire a pool slot, create a statement bound to that slot's connection,
     * and return a Statement wrapper that releases the slot after execute().
     */
    #[Override]
    public function createStatement(
        ?string $initialSql = null,
        ParameterContainer|array $initialParameters = [],
    ): StatementInterface {
        $inner     = $this->pool->acquire();
        $statement = $inner->createStatement($initialSql, $initialParameters);
        return new Statement($statement, $this->pool, $inner);
    }

    // -------------------------------------------------------------------------
    // Metadata — identical for every pool slot; delegate to the primary adapter.
    // -------------------------------------------------------------------------

    #[Override]
    public function getDriver(): DriverInterface
    {
        return $this->primary->getDriver();
    }

    #[Override]
    public function getPlatform(): PlatformInterface
    {
        return $this->primary->getPlatform();
    }

    #[Override]
    public function getProfiler(): ?ProfilerInterface
    {
        return $this->primary->getProfiler();
    }

    #[Override]
    public function getQueryResultSetPrototype(): ResultSetInterface
    {
        return $this->primary->getQueryResultSetPrototype();
    }

    /**
     * @inheritDoc
     * @todo Tracked upstream as 0.3.x — not used by the pool adapter path.
     */
    #[Override]
    public function getHelpers(): array
    {
        return [];
    }
}
