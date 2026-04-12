<?php

declare(strict_types=1);

namespace Mezzio\Async\Runner;

use Async\Scope;
use Async\Signal;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Mezzio\Async\Http\RequestParser;
use Mezzio\Async\Http\ResponseEmitter;
use Mezzio\Async\Http\StaticFileHandler;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function Async\await;
use function Async\await_any_or_fail;
use function Async\signal;
use function Async\spawn;
use function fclose;
use function hrtime;
use function is_resource;
use function round;
use function sprintf;
use function stream_socket_accept;
use function stream_socket_server;

use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;

/**
 * Runs the Mezzio pipeline as a TrueAsync long-lived HTTP server.
 *
 * Lifecycle:
 *   1. Bind a TCP server socket.
 *   2. Spawn an accept loop inside a Scope.
 *   3. Each accepted connection is handled in its own coroutine (spawned into
 *      the same Scope so the Scope's exception handler shields the accept loop).
 *   4. A signal coroutine waits for SIGTERM/SIGINT and cancels the Scope.
 *   5. Scope::awaitAfterCancellation() drains in-flight connections gracefully.
 */
final readonly class AsyncRunner implements RequestHandlerRunnerInterface
{
    public function __construct(
        private RequestHandlerInterface $handler,
        private RequestParser $parser,
        private ResponseEmitter $emitter,
        private StaticFileHandler $staticFiles,
        private LoggerInterface $logger,
        private string $host,
        private int $port,
    ) {}

    public function run(): void
    {
        $context = stream_context_create(['socket' => ['tcp_nodelay' => true]]);
        $server  = stream_socket_server(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($server === false) {
            throw new \RuntimeException(
                sprintf('Cannot bind %s:%d — %s (%d)', $this->host, $this->port, $errstr, $errno)
            );
        }

        $this->logger->notice(
            sprintf('mezzio-async listening on http://%s:%d', $this->host, $this->port)
        );

        // Everything must run inside a coroutine. await(spawn(...)) enters the
        // TrueAsync scheduler and blocks the main thread until the server stops.
        await(spawn(function () use ($server): void {
            $scope = new Scope();

            // Shield the accept loop from individual connection errors
            $scope->setExceptionHandler(function (Throwable $e): void {
                $this->logger->error('Unhandled connection error', ['exception' => $e]);
            });

            // Accept loop coroutine
            $scope->spawn(function () use ($server, $scope): void {
                try {
                    while (true) {
                        $peerName = '';
                        $conn     = @stream_socket_accept($server, -1, $peerName);

                        if ($conn === false) {
                            // Accept interrupted — scope is being cancelled
                            break;
                        }

                        $scope->spawn($this->handleConnection(...), $conn, $peerName);
                    }
                } finally {
                    fclose($server);
                }
            });

            // Suspend here until SIGTERM or SIGINT arrives
            await_any_or_fail([
                signal(Signal::SIGTERM),
                signal(Signal::SIGINT),
            ]);

            $this->logger->notice('Shutdown signal received, draining connections…');
            $scope->cancel();

            // Scope is now cancelled — safe to drain in-flight connections
            $scope->awaitAfterCancellation(
                errorHandler: fn(Throwable $e) => $this->logger->error(
                    'Error during shutdown drain',
                    ['exception' => $e]
                )
            );
        }));

        $this->logger->notice('mezzio-async stopped');
    }

    private function handleConnection(mixed $conn, string $peerName): void
    {
        $startNs = null;
        $method  = 'UNKNOWN';
        $target  = '/';
        $status  = 500;

        try {
            $request  = $this->parser->parse($conn, $peerName);
            $method   = $request->getMethod();
            $target   = $request->getRequestTarget();
            $startNs  = hrtime(true); // start timing after parse

            // Short-circuit for static assets — bypass Mezzio pipeline
            if ($this->staticFiles->tryServe($method, $target, $conn)) {
                $status = 200;
                return;
            }

            $response = $this->handler->handle($request);
            $status   = $response->getStatusCode();
            $this->emitter->emit($response, $conn);
        } catch (Throwable $e) {
            $this->logger->error(
                'Request error from ' . $peerName,
                ['exception' => $e]
            );
            if (is_resource($conn)) {
                $this->emitter->emitError($conn, 500, 'Internal Server Error');
            }
        } finally {
            $ms = $startNs !== null
                ? round((hrtime(true) - $startNs) / 1_000_000, 2)
                : 0.0;
            $this->logger->info(sprintf(
                '%s %s %d %sms %s',
                $method,
                $target,
                $status,
                $ms,
                $peerName,
            ));

            if (is_resource($conn)) {
                fclose($conn);
            }
        }
    }
}
