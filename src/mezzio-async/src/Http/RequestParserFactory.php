<?php

declare(strict_types=1);

namespace Mezzio\Async\Http;

use Psr\Container\ContainerInterface;

final readonly class RequestParserFactory
{
    public function __invoke(ContainerInterface $container): RequestParser
    {
        return new RequestParser(
            $container->get(ServerRequestFactory::class),
        );
    }
}
