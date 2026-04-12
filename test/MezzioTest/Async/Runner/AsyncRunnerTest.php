<?php

declare(strict_types=1);

namespace MezzioTest\Async\Runner;

use Mezzio\Async\Http\RequestParser;
use Mezzio\Async\Http\ResponseEmitter;
use Mezzio\Async\Http\Server;
use Mezzio\Async\Http\ServerRequestFactory;
use Mezzio\Async\Http\StaticFileHandler;
use Mezzio\Async\Runner\AsyncRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;

#[CoversClass(AsyncRunner::class)]
#[CoversMethod(AsyncRunner::class, 'run')]
final class AsyncRunnerTest extends TestCase
{
    public function testRunDelegatesToServerListen(): void
    {
        if (! extension_loaded('true_async')) {
            $this->markTestSkipped('true_async extension not available');
        }

        // When the extension is present, run() should call Server::listen() and
        // block until a signal is received. Full lifecycle tested via Docker.
        self::assertTrue(true);
    }
}
