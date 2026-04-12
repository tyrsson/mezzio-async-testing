<?php

declare(strict_types=1);

namespace MezzioTest\Async\Runner;

use AppTest\InMemoryContainer;
use Mezzio\Async\Http\RequestParser;
use Mezzio\Async\Http\ResponseEmitter;
use Mezzio\Async\Http\Server;
use Mezzio\Async\Http\ServerRequestFactory;
use Mezzio\Async\Http\StaticFileHandler;
use Mezzio\Async\Runner\AsyncRunner;
use Mezzio\Async\Runner\AsyncRunnerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;

#[CoversClass(AsyncRunnerFactory::class)]
final class AsyncRunnerFactoryTest extends TestCase
{
    private InMemoryContainer $container;
    private AsyncRunnerFactory $factory;

    protected function setUp(): void
    {
        $this->container = new InMemoryContainer();
        $this->factory   = new AsyncRunnerFactory();

        $logger = new NullLogger();

        $this->container->setService('Mezzio\ApplicationPipeline', $this->createMock(RequestHandlerInterface::class));
        $this->container->setService(RequestParser::class, new RequestParser(new ServerRequestFactory()));
        $this->container->setService(ResponseEmitter::class, new ResponseEmitter());
        $this->container->setService(StaticFileHandler::class, new StaticFileHandler(__DIR__));
        $this->container->setService(\Psr\Log\LoggerInterface::class, $logger);
        $this->container->setService(Server::class, new Server('0.0.0.0', 8080, $logger));
    }

    public function testReturnsAsyncRunnerInstance(): void
    {
        $runner = ($this->factory)($this->container);

        self::assertInstanceOf(AsyncRunner::class, $runner);
    }
}
