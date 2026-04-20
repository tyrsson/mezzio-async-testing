<?php

declare(strict_types=1);

namespace PhpDb\Async\Container;

use Async\Pool;
use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Async\Adapter;
use PhpDb\ConfigProvider;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function extension_loaded;
use function is_array;

/**
 * Abstract DI factory for named pool-backed adapters.
 *
 * Mirrors PhpDb\Container\AbstractAdapterInterfaceFactory but returns
 * PhpDb\Async\Adapter instances backed by independent Async\Pool connection
 * pools. This enables separate read and write pools with independent sizing:
 *
 *   AdapterInterface::class => [
 *       // Primary adapter config …
 *       'adapters' => [                           // ConfigProvider::NAMED_ADAPTER_KEY
 *           'db.read' => [
 *               'connection' => [...],
 *               'pool'       => ['min' => 5, 'max' => 20],
 *           ],
 *           'db.write' => [
 *               'connection' => [...],
 *               'pool'       => ['min' => 1, 'max' => 5],
 *           ],
 *       ],
 *   ],
 *
 * Usage:
 *   $readAdapter  = $container->get('db.read');   // PhpDb\Async\Adapter
 *   $writeAdapter = $container->get('db.write');  // PhpDb\Async\Adapter
 */
final class AbstractAdapterFactory implements AbstractFactoryInterface
{
    private ?array $config = null;

    /**
     * @param string $requestedName
     */
    public function canCreate(ContainerInterface $container, $requestedName): bool
    {
        $config = $this->getNamedAdapterConfig($container);

        return isset($config[$requestedName])
            && is_array($config[$requestedName])
            && $config[$requestedName] !== [];
    }

    /**
     * @param string $requestedName
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null,
    ): Adapter {
        if (! extension_loaded('true_async')) {
            throw new RuntimeException(
                'The true_async extension is required for ' . Adapter::class . '. '
                . 'Use PhpDb\Pgsql\ConfigProvider for non-async environments.'
            );
        }

        $namedConfig   = $this->getNamedAdapterConfig($container);
        $adapterConfig = $namedConfig[$requestedName];
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

        $primary = $manager();

        return new Adapter($pool, $primary);
    }

    /**
     * Reads named adapter configuration from
     * config[AdapterInterface::class][ConfigProvider::NAMED_ADAPTER_KEY].
     */
    private function getNamedAdapterConfig(ContainerInterface $container): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        if (! $container->has('config')) {
            $this->config = [];
            return $this->config;
        }

        $config       = $container->get('config');
        $this->config = $config[AdapterInterface::class][ConfigProvider::NAMED_ADAPTER_KEY] ?? [];

        return $this->config;
    }
}
