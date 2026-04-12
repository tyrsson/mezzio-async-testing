<?php

declare(strict_types=1);

namespace Mezzio\Async;

use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Mezzio\Async\Http\RequestParser;
use Mezzio\Async\Http\ResponseEmitter;
use Mezzio\Async\Http\Server;
use Mezzio\Async\Http\ServerFactory;
use Mezzio\Async\Http\ServerRequestFactory;
use Mezzio\Async\Http\StaticFileHandler;
use Mezzio\Async\Log\LoggerDelegator;
use Mezzio\Async\Runner\AsyncRunner;
use Mezzio\Async\Runner\AsyncRunnerFactory;
use Psr\Log\LoggerInterface;

use const PHP_SAPI;

final class ConfigProvider
{
    public function __invoke(): array
    {
        $config = PHP_SAPI === 'cli'
            ? ['dependencies' => $this->getDependencies()]
            : [];

        $config['mezzio-async'] = $this->getDefaultConfig();

        return $config;
    }

    public function getDependencies(): array
    {
        return [
            'delegators' => [
                LoggerInterface::class => [
                    LoggerDelegator::class,
                ],
            ],
            'factories' => [
                AsyncRunner::class          => AsyncRunnerFactory::class,
                RequestParser::class        => \Mezzio\Async\Http\RequestParserFactory::class,
                ResponseEmitter::class      => \Mezzio\Async\Http\ResponseEmitterFactory::class,
                Server::class               => ServerFactory::class,
                ServerRequestFactory::class => \Mezzio\Async\Http\ServerRequestFactoryFactory::class,
                StaticFileHandler::class    => \Mezzio\Async\Http\StaticFileHandlerFactory::class,
            ],
            'aliases' => [
                RequestHandlerRunnerInterface::class => AsyncRunner::class,
            ],
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'host' => '0.0.0.0',
            'port' => 8080,
        ];
    }
}
