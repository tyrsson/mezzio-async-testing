<?php

declare(strict_types=1);

namespace PhpDb\Async\Pdo;

use PDO;
use PhpDb\Adapter\Driver\Pdo\Result;
use PhpDb\Adapter\Driver\Pdo\Statement;
use PhpDb\Adapter\Driver\PdoDriverAwareInterface;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\Adapter\Driver\StatementInterface;
use PhpDb\Pgsql\Pdo\Connection;
use RuntimeException;

use function extension_loaded;

/**
 * Pool-enabled PDO driver for TrueAsync environments.
 *
 * Extends PhpDb\Pgsql\Pdo\Driver and takes responsibility for constructing
 * the pool-enabled PDO object from connection parameters and pool config,
 * so consumers never need to know about PDO::ATTR_POOL_ENABLED.
 *
 * The pool is baked into the PDO object at construction time. PHP core manages
 * the connection-to-coroutine binding transparently — no Async\Pool, no
 * acquire()/release(), and no suspend() polling loop is required.
 *
 * Requires: ext-true_async (PHP 8.6+)
 */
final class Driver extends \PhpDb\Pgsql\Pdo\Driver
{
    /**
     * @param array<string, mixed> $connectionConfig
     * @param array<string, mixed> $poolConfig
     */
    public function __construct(
        array $connectionConfig,
        array $poolConfig = [],
        StatementInterface&PdoDriverAwareInterface $statementPrototype = new Statement(),
        ResultInterface $resultPrototype = new Result(),
    ) {
        if (! extension_loaded('true_async')) {
            throw new RuntimeException(
                'The true_async extension is required for ' . self::class . '. '
                . 'Use PhpDb\Pgsql\Pdo\Driver for non-async environments.'
            );
        }

        // @phpstan-ignore cast.string
        $dsn      = (string) $connectionConfig['dsn'];
        // @phpstan-ignore cast.string
        $username = isset($connectionConfig['username']) ? (string) $connectionConfig['username'] : null;
        // @phpstan-ignore cast.string
        $password = isset($connectionConfig['password']) ? (string) $connectionConfig['password'] : null;

        $pdo = new PDO(
            $dsn,
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE                   => PDO::ERRMODE_EXCEPTION,
                /** @phpstan-ignore classConstant.notFound, array.invalidKey */
                PDO::ATTR_POOL_ENABLED              => true,
                /** @phpstan-ignore classConstant.notFound, array.invalidKey, cast.int */
                PDO::ATTR_POOL_MIN                  => (int) ($poolConfig['min'] ?? 2),
                /** @phpstan-ignore classConstant.notFound, array.invalidKey, cast.int */
                PDO::ATTR_POOL_MAX                  => (int) ($poolConfig['max'] ?? 10),
                /** @phpstan-ignore classConstant.notFound, array.invalidKey, cast.int */
                PDO::ATTR_POOL_HEALTHCHECK_INTERVAL => (int) ($poolConfig['healthcheck_interval'] ?? 30),
            ],
        );

        parent::__construct(new Connection($pdo), $statementPrototype, $resultPrototype);
    }
}
