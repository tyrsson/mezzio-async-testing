<?php

declare(strict_types=1);

namespace PhpDb\Async\Container;

use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use PhpDb\Adapter\Adapter;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Async\Pdo;
use PhpDb\Pgsql\AdapterPlatform;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function extension_loaded;
use function is_array;

/**
 * Abstract DI factory for named PDO-pool-backed adapters.
 *
 * Mirrors AbstractAdapterFactory but uses the PDO pool path instead of
 * Async\Pool + native pgsql. Enables separate read/write adapters, each with
 * their own pool-enabled PDO instance:
 *
 *   'pdo-adapters' => [
 *       'pdo.read' => [
 *           'connection' => ['dsn' => '...', 'username' => '...', 'password' => '...'],
 *           'pool'       => ['min' => 5, 'max' => 20],
 *       ],
 *       'pdo.write' => [
 *           'connection' => ['dsn' => '...', 'username' => '...', 'password' => '...'],
 *           'pool'       => ['min' => 1, 'max' => 5],
 *       ],
 *   ],
 *
 * Usage:
 *   $readAdapter  = $container->get('pdo.read');   // PhpDb\Adapter\Adapter
 *   $writeAdapter = $container->get('pdo.write');  // PhpDb\Adapter\Adapter
 */
final class PdoAbstractAdapterFactory implements AbstractFactoryInterface
{
    /** @var array<string, mixed>|null */
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
    ): AdapterInterface {
        if (! extension_loaded('true_async')) {
            throw new RuntimeException(
                'The true_async extension is required for ' . Pdo\Driver::class . '. '
                . 'Use PhpDb\Pgsql\Pdo\Driver for non-async environments.'
            );
        }

        $namedConfig   = $this->getNamedAdapterConfig($container);
        /** @var array{connection?: array<string, mixed>, pool?: array<string, mixed>} $adapterConfig */
        $adapterConfig = $namedConfig[$requestedName];

        $driver   = new Pdo\Driver(
            $adapterConfig['connection'] ?? [],
            $adapterConfig['pool'] ?? [],
        );
        $platform = new AdapterPlatform($driver);

        return new Adapter($driver, $platform);
    }

    /**
     * Reads named PDO adapter configuration from config['pdo-adapters'].
     *
     * @return array<string, mixed>
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

        /** @var array<string, mixed> $config */
        $config       = $container->get('config');
        /** @var array<string, mixed> $pdoAdapters */
        $pdoAdapters  = $config['pdo-adapters'] ?? [];
        $this->config = $pdoAdapters;

        return $this->config;
    }
}
