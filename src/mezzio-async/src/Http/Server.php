<?php

declare(strict_types=1);

namespace Mezzio\Async\Http;

use Async\Scope;
use Async\Signal;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function Async\await;
use function Async\await_any_or_fail;
use function Async\signal;
use function Async\spawn;
use function fclose;
use function socket_import_stream;
use function socket_set_option;
use function sprintf;
use function stream_context_create;
use function stream_socket_accept;
use function stream_socket_server;

use const SOL_TCP;
use const TCP_NODELAY;

use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;

/**
 * Owns the TCP socket, TrueAsync scheduler entry, Scope lifecycle,
 * accept loop, and signal handling.
 *
 * The connection handler callable is responsible only for what to do
 * with each accepted connection.
 */
final readonly class Server
{
    public function __construct(
        private string $host,
        private int $port,
        private LoggerInterface $logger,
    ) {}

    /**
     * Binds the socket, enters the TrueAsync scheduler, and calls
     * $connectionHandler for every accepted connection.
     *
     * Blocks until SIGTERM or SIGINT is received, then drains gracefully.
     *
     * @param callable(mixed $conn, string $peerName): void $connectionHandler
     */
    public function listen(callable $connectionHandler): void
    {
        $context = stream_context_create(['socket' => ['tcp_nodelay' => true, 'backlog' => 4096]]);
        $server  = stream_socket_server(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($server === false) {
            throw new RuntimeException(
                sprintf('Cannot bind %s:%d — %s (%d)', $this->host, $this->port, $errstr, $errno)
            );
        }

        $this->logger->notice(
            sprintf('mezzio-async listening on http://%s:%d', $this->host, $this->port)
        );

        await(spawn(function () use ($server, $connectionHandler): void {
            $scope = new Scope();

            $scope->setExceptionHandler(function (Throwable $e): void {
                $this->logger->error('Unhandled connection error', ['exception' => $e]);
            });

            // Accept loop coroutine
            $scope->spawn(function () use ($server, $scope, $connectionHandler): void {
                try {
                    while (true) {
                        $peerName = '';
                        $conn     = @stream_socket_accept($server, -1, $peerName);

                        if ($conn === false) {
                            // Accept interrupted — scope is being cancelled
                            break;
                        }

                        // TCP_NODELAY is not inherited from the listening socket on Linux.
                        // Without it, Nagle's algorithm + client delayed-ACK (~40 ms) adds
                        // ~39 ms latency per keep-alive response when the emitter does
                        // multiple small fwrite() calls (status line, headers, body).
                        $sock = socket_import_stream($conn);
                        if ($sock !== false) {
                            socket_set_option($sock, SOL_TCP, TCP_NODELAY, 1);
                        }

                        $scope->spawn($connectionHandler, $conn, $peerName);
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

            $scope->awaitAfterCancellation(
                errorHandler: fn(Throwable $e) => $this->logger->error(
                    'Error during shutdown drain',
                    ['exception' => $e]
                )
            );
        }));

        $this->logger->notice('mezzio-async stopped');
    }
}
