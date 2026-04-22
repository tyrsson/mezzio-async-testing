<?php

declare(strict_types=1);

namespace Mezzio\Async\Runner;

use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Mezzio\Async\Http\RequestParser;
use Mezzio\Async\Http\ResponseEmitter;
use Mezzio\Async\Http\Server;
use Mezzio\Async\Http\StaticFileHandler;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function fclose;
use function is_resource;
use function strtolower;

/**
 * Integrates the Mezzio pipeline with the TrueAsync HTTP server.
 *
 * Delegates socket management, scheduler entry, Scope lifecycle, accept loop,
 * and signal handling to Http\Server. This class is responsible solely for
 * handling individual connections: parsing, dispatching, and emitting responses.
 */
final readonly class AsyncRunner implements RequestHandlerRunnerInterface
{
    public function __construct(
        private RequestHandlerInterface $handler,
        private RequestParser $parser,
        private ResponseEmitter $emitter,
        private StaticFileHandler $staticFiles,
        private Server $server,
    ) {}

    public function run(): void
    {
        $this->server->listen($this->handleConnection(...));
    }

    private function handleConnection(mixed $conn, string $peerName): void
    {
        try {
            while (true) {
                try {
                    $request = $this->parser->parse($conn, $peerName);
                } catch (Throwable $e) {
                    break;
                }

                if ($request === null) {
                    break;
                }

                $keepAlive = true;

                try {
                    $isHttp10   = $request->getProtocolVersion() === '1.0';
                    $connHeader = strtolower($request->getHeaderLine('Connection'));
                    $keepAlive  = ! $isHttp10 && $connHeader !== 'close';

                    if ($this->staticFiles->tryServe($request->getMethod(), $request->getRequestTarget(), $conn)) {
                        $keepAlive = false;
                        break;
                    }

                    $response  = $this->handler->handle($request);
                    $response  = $response->withHeader('Connection', $keepAlive ? 'keep-alive' : 'close');
                    $keepAlive = $this->emitter->emit($response, $conn) && $keepAlive;
                } catch (Throwable $e) {
                    if (is_resource($conn)) {
                        $this->emitter->emitError($conn, 500, 'Internal Server Error');
                    }
                    $keepAlive = false;
                }

                if (! $keepAlive) {
                    break;
                }
            }
        } finally {
            if (is_resource($conn)) {
                fclose($conn);
            }
        }
    }
}
