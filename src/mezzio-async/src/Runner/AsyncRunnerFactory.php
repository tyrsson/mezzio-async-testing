<?php

declare(strict_types=1);

namespace Mezzio\Async\Runner;

use Mezzio\Async\Http\RequestParser;
use Mezzio\Async\Http\ResponseEmitter;
use Mezzio\Async\Http\StaticFileHandler;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class AsyncRunnerFactory
{
    public function __invoke(ContainerInterface $container): AsyncRunner
    {
        $config      = $container->has('config') ? $container->get('config') : [];
        $asyncConfig = $config['mezzio-async'] ?? [];
        $httpConfig  = $asyncConfig['http-server'] ?? $asyncConfig;

        return new AsyncRunner(
            handler:      $container->get('Mezzio\ApplicationPipeline'),
            parser:       $container->get(RequestParser::class),
            emitter:      $container->get(ResponseEmitter::class),
            staticFiles:  $container->get(StaticFileHandler::class),
            logger:       $container->get(LoggerInterface::class),
            host:         (string) ($httpConfig['host'] ?? '0.0.0.0'),
            port:         (int)    ($httpConfig['port'] ?? 8080),
        );
    }
}
