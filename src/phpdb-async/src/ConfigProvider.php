<?php

declare(strict_types=1);

namespace PhpDb\Async;

use PhpDb\Adapter\AdapterInterface;

/**
 * Registers the pool-backed Adapter as the AdapterInterface implementation.
 *
 * Load this ConfigProvider in place of (or after) PhpDb\Pgsql\ConfigProvider in
 * the config aggregator. The pgsql driver/connection/platform services registered
 * by PhpDb\Pgsql\ConfigProvider remain in effect; only the top-level Adapter
 * factory is overridden.
 *
 * Requires: ext-true_async (PHP 8.6+)
 */
final class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }

    public function getDependencies(): array
    {
        return [
            'aliases'            => [
                AdapterInterface::class => Adapter::class,
            ],
            'factories'          => [
                Adapter::class => Container\AdapterFactory::class,
                'pdo-adapter'  => Container\PdoAdapterFactory::class,
            ],
            'abstract_factories' => [
                Container\AbstractAdapterFactory::class,
                Container\PdoAbstractAdapterFactory::class,
            ],
        ];
    }
}
