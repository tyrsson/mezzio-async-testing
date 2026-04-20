<?php

declare(strict_types=1);

namespace PhpDb\Async\Container;

use Async\Pool;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Async\Adapter;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function extension_loaded;

/**
 * DI factory for the primary PhpDb\Async\Adapter service.
 *
 * Reads connection configuration from config[AdapterInterface::class] and
 * pool sizing from the optional 'pool' sub-key:
 *
 *   AdapterInterface::class => [
 *       'driver'     => PhpDb\Pgsql\Driver::class,   // informational; not used directly
 *       'connection' => [
 *           'host'     => '127.0.0.1',
 *           'port'     => 5432,
 *           'database' => 'mydb',
 *           'username' => 'app',
 *           'password' => 'secret',
 *       ],
 *       'pool' => [
 *           'min'                    => 2,
 *           'max'                    => 10,
 *           'healthcheck_interval_ms' => 30000,
 *       ],
 *   ],
 *
 * All pool callbacks are registered via array-callable syntax on the
 * ConnectionManager — no Closure objects are created.
 */
final class AdapterFactory
{
    public function __invoke(ContainerInterface $container): Adapter
    {
        if (! extension_loaded('true_async')) {
            throw new RuntimeException(
                'The true_async extension is required for ' . Adapter::class . '. '
                . 'Use PhpDb\Pgsql\ConfigProvider for non-async environments.'
            );
        }

        $config        = $container->has('config') ? $container->get('config') : [];
        $adapterConfig = $config[AdapterInterface::class] ?? [];
        $poolConfig    = $adapterConfig['pool'] ?? [];

        $manager = new ConnectionManager($adapterConfig['connection'] ?? []);

        $pool = new Pool(
            factory:             $manager,
            destructor:          [$manager, 'destroy'],
            healthcheck:         [$manager, 'isHealthy'],
            beforeRelease:       [$manager, 'isReleasable'],
            min:                 (int) ($poolConfig['min'] ?? 2),
            max:                 (int) ($poolConfig['max'] ?? 10),
            healthcheckInterval: (int) ($poolConfig['healthcheck_interval_ms'] ?? 30_000),
        );

        // Primary adapter: one persistent adapter for metadata and sequential
        // operations (getDriver, getPlatform, TableGateway writes, etc.)
        $primary = $manager();

        return new Adapter($pool, $primary);
    }
}
