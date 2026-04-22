<?php

declare(strict_types=1);

namespace Mezzio\Async\Runner;

use Mezzio\Async\Http\RequestParser;
use Mezzio\Async\Http\ResponseEmitter;
use Mezzio\Async\Http\Server;
use Mezzio\Async\Http\StaticFileHandler;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AsyncRunnerFactory
{
    public function __invoke(ContainerInterface $container): AsyncRunner
    {
        return new AsyncRunner(
            handler:     $container->get('Mezzio\ApplicationPipeline'),
            parser:      $container->get(RequestParser::class),
            emitter:     $container->get(ResponseEmitter::class),
            staticFiles: $container->get(StaticFileHandler::class),
            server:      $container->get(Server::class),
        );
    }
}
