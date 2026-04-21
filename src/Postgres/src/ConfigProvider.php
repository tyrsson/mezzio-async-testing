<?php

declare(strict_types=1);

/**
 * This file is part of the Webware Mezzio Bleeding Edge package.
 *
 * Copyright (c) 2026 Joey Smith <jsmith@webinertia.net>
 * and contributors.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Postgres;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Pgsql\Driver;

final class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
            'router'       => $this->getRouteProviders(),
            'templates'    => $this->getTemplates(),
            AdapterInterface::class => [
                'driver'     => Driver::class,
                'connection' => [
                    'host'     => 'postgres',
                    'port'     => 5432,
                    'database' => 'phpdb_test',
                    'username' => 'postgres',
                    'password' => 'postgres',
                ],
                'pool' => [
                    'min'                     => 2,
                    'max'                     => 100,
                    'healthcheck_interval_ms' => 30_000,
                ],
            ],
            'pdo-adapter' => [
                'connection' => [
                    'dsn'      => 'pgsql:host=postgres;port=5432;dbname=phpdb_test',
                    'username' => 'postgres',
                    'password' => 'postgres',
                ],
                'pool' => [
                    'min'                  => 2,
                    'max'                  => 100,
                    'healthcheck_interval' => 30,
                ],
            ],
        ];
    }

    public function getDependencies(): array
    {
        return [
            'factories' => [
                Handler\PgsqlHandler::class  => Container\PgsqlHandlerFactory::class,
                Handler\PdoHandler::class    => Container\PdoHandlerFactory::class,
                Handler\SpawnHandler::class  => Container\SpawnHandlerFactory::class,
                RouteProvider::class         => Container\RouteProviderFactory::class,
            ],
        ];
    }

    public function getRouteProviders(): array
    {
        return [
            'route-providers' => [
                RouteProvider::class,
            ],
        ];
    }

    public function getTemplates(): array
    {
        return [
            'map'   => [
                'postgres::pgsql' => __DIR__ . '/../templates/postgres/pgsql.phtml',
                'postgres::pdo'   => __DIR__ . '/../templates/postgres/pdo.phtml',
            ],
            'paths' => [
                'postgres' => [__DIR__ . '/../templates/postgres'],
            ],
        ];
    }
}
