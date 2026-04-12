<?php

declare(strict_types=1);

namespace Mezzio\Async\Http;

use Psr\Container\ContainerInterface;

final readonly class ResponseEmitterFactory
{
    public function __invoke(ContainerInterface $container): ResponseEmitter
    {
        return new ResponseEmitter();
    }
}
