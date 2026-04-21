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

namespace Postgres\Container;

use Mezzio\Template\TemplateRendererInterface;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Async\Adapter;
use Postgres\Handler\PgsqlHandler;
use Psr\Container\ContainerInterface;

final readonly class PgsqlHandlerFactory
{
    public function __invoke(ContainerInterface $container): PgsqlHandler
    {
        $adapter = $container->get(AdapterInterface::class);
        assert($adapter instanceof Adapter);

        return new PgsqlHandler(
            $container->get(TemplateRendererInterface::class),
            $adapter,
        );
    }
}
