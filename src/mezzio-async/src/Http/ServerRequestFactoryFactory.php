<?php

declare(strict_types=1);

namespace Mezzio\Async\Http;

use Psr\Container\ContainerInterface;

final readonly class ServerRequestFactoryFactory
{
    public function __invoke(ContainerInterface $container): ServerRequestFactory
    {
        return new ServerRequestFactory();
    }
}
