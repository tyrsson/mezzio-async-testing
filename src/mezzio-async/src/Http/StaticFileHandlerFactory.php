<?php

declare(strict_types=1);

namespace Mezzio\Async\Http;

use Psr\Container\ContainerInterface;

final class StaticFileHandlerFactory
{
    public function __invoke(ContainerInterface $container): StaticFileHandler
    {
        return new StaticFileHandler('public');
    }
}
