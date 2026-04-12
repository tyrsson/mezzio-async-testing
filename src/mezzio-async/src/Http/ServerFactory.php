<?php

declare(strict_types=1);

namespace Mezzio\Async\Http;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final readonly class ServerFactory
{
    public function __invoke(ContainerInterface $container): Server
    {
        $config      = $container->has('config') ? $container->get('config') : [];
        $asyncConfig = $config['mezzio-async'] ?? [];
        $httpConfig  = $asyncConfig['http-server'] ?? $asyncConfig;

        return new Server(
            host:   (string) ($httpConfig['host'] ?? '0.0.0.0'),
            port:   (int)    ($httpConfig['port'] ?? 8080),
            logger: $container->get(LoggerInterface::class),
        );
    }
}
