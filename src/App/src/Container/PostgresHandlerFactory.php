<?php

declare(strict_types=1);

namespace App\Container;

use App\Handler\PostgresHandler;
use Mezzio\Template\TemplateRendererInterface;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Async\Adapter;
use Psr\Container\ContainerInterface;

final readonly class PostgresHandlerFactory
{
    public function __invoke(ContainerInterface $container): PostgresHandler
    {
        $adapter = $container->get(AdapterInterface::class);
        assert($adapter instanceof Adapter);

        return new PostgresHandler(
            $container->get(TemplateRendererInterface::class),
            $adapter,
        );
    }
}