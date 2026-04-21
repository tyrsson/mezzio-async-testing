<?php

declare(strict_types=1);

namespace PhpDb\Async\Container;

use PhpDb\Adapter\Adapter;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Async\Pdo;
use PhpDb\Pgsql\AdapterPlatform;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function extension_loaded;

/**
 * DI factory for the primary PDO-pool-backed adapter service.
 *
 * Reads configuration from config['pdo-adapter']:
 *
 *   'pdo-adapter' => [
 *       'connection' => [
 *           'dsn'      => 'pgsql:host=postgres;port=5432;dbname=mydb',
 *           'username' => 'app',
 *           'password' => 'secret',
 *       ],
 *       'pool' => [
 *           'min'                  => 2,
 *           'max'                  => 10,
 *           'healthcheck_interval' => 30,
 *       ],
 *   ],
 *
 * Returns a plain PhpDb\Adapter\Adapter (not PhpDb\Async\Adapter).
 * No Async\Pool, no ConnectionManager, no acquire()/release().
 */
final class PdoAdapterFactory
{
    public function __invoke(ContainerInterface $container): AdapterInterface
    {
        if (! extension_loaded('true_async')) {
            throw new RuntimeException(
                'The true_async extension is required for ' . Pdo\Driver::class . '. '
                . 'Use PhpDb\Pgsql\Pdo\Driver for non-async environments.'
            );
        }

        /** @var array<string, mixed> $config */
        $config        = $container->has('config') ? $container->get('config') : [];
        /** @var array{connection?: array<string, mixed>, pool?: array<string, mixed>} $adapterConfig */
        $adapterConfig = $config['pdo-adapter'] ?? [];

        $driver   = new Pdo\Driver(
            $adapterConfig['connection'] ?? [],
            $adapterConfig['pool'] ?? [],
        );
        $platform = new AdapterPlatform($driver);

        return new Adapter($driver, $platform);
    }
}
