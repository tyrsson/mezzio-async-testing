<?php

declare(strict_types=1);

namespace Mezzio\Async\Runner;

use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Mezzio\Async\Http\RequestParser;
use Mezzio\Async\Http\ResponseEmitter;
use Mezzio\Async\Http\Server;
use Mezzio\Async\Http\StaticFileHandler;
use Monolog\Logger;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function fclose;
use function hrtime;
use function is_resource;
use function round;
use function sprintf;
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
        private LoggerInterface&Logger $logger,
        private Server $server,
    ) {}

    public function run(): void
    {
        $this->server->listen($this->handleConnection(...));
    }

    private function handleConnection(mixed $conn, string $peerName): void
    {
        // Outer try/finally owns the connection lifetime — fclose runs exactly once
        // regardless of how many requests were served or how the loop exits.
        try {
            while (true) {
                // Parse OUTSIDE per-request logging — null = clean close (speculative
                // pre-connect or keep-alive idle), no log entry should be produced.
                try {
                    $request = $this->parser->parse($conn, $peerName);
                } catch (Throwable $e) {
                    $this->logger->warning('Parse error from ' . $peerName, ['exception' => $e]);
                    break;
                }

                if ($request === null) {
                    // Clean close — speculative pre-connect or client closed idle connection
                    break;
                }

                // Real request — always log it
                $method    = $request->getMethod();
                $target    = $request->getRequestTarget();
                $startNs   = hrtime(true);
                $status    = 500;
                $isStatic  = false;
                $keepAlive = true;

                try {
                    $isHttp10   = $request->getProtocolVersion() === '1.0';
                    $connHeader = strtolower($request->getHeaderLine('Connection'));
                    $keepAlive  = ! $isHttp10 && $connHeader !== 'close';

                    if ($this->staticFiles->tryServe($method, $target, $conn)) {
                        // Static handler writes its own complete response including
                        // Connection: close — break out of the keep-alive loop.
                        $isStatic  = true;
                        $status    = 200;
                        $keepAlive = false;
                        break;
                    }

                    $response  = $this->handler->handle($request);
                    $status    = $response->getStatusCode();
                    $response  = $response->withHeader('Connection', $keepAlive ? 'keep-alive' : 'close');
                    $keepAlive = $this->emitter->emit($response, $conn) && $keepAlive;
                } catch (Throwable $e) {
                    $this->logger->error('Request error from ' . $peerName, ['exception' => $e]);
                    if (is_resource($conn)) {
                        $this->emitter->emitError($conn, 500, 'Internal Server Error');
                    }
                    $keepAlive = false;
                } finally {
                    if (! $isStatic) {
                        $ms = round((hrtime(true) - $startNs) / 1_000_000, 2);
                        $this->logger->info(sprintf(
                            '%s %s %d %sms %s',
                            $method,
                            $target,
                            $status,
                            $ms,
                            $peerName,
                        ));
                    }
                }

                if (! $keepAlive) {
                    break;
                }
            }
        } finally {
            if (is_resource($conn)) {
                fclose($conn);
            }
            $this->logger->close();
        }
    }
}
