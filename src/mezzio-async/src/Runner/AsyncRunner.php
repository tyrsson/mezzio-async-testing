<?php

declare(strict_types=1);

namespace Mezzio\Async\Runner;

use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Mezzio\Async\Http\RequestParser;
use Mezzio\Async\Http\ResponseEmitter;
use Mezzio\Async\Http\Server;
use Mezzio\Async\Http\StaticFileHandler;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function fclose;
use function hrtime;
use function is_resource;
use function round;
use function sprintf;

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
        private LoggerInterface $logger,
        private Server $server,
    ) {}

    public function run(): void
    {
        $this->server->listen($this->handleConnection(...));
    }

    private function handleConnection(mixed $conn, string $peerName): void
    {
        // Clock starts at connection entry — measures read + parse + dispatch + emit.
        $startNs = hrtime(true);

        // Parse is kept outside the logging try/finally intentionally.
        // Browser speculative pre-connects open a TCP connection but never send data,
        // causing parse() to return null. These should be silently dropped — no log entry.
        // Parse errors (mid-stream disconnect) are caught here too and closed quietly.
        try {
            $request = $this->parser->parse($conn, $peerName);
        } catch (Throwable $e) {
            $this->logger->warning('Parse error from ' . $peerName, ['exception' => $e]);
            fclose($conn);
            return;
        }

        if ($request === null) {
            // Speculative pre-connect — no bytes were ever sent
            fclose($conn);
            return;
        }

        // From here on we have a real request; always log it.
        $method    = $request->getMethod();
        $target    = $request->getRequestTarget();
        $status    = 500;
        $isStatic  = false;

        try {
            if ($this->staticFiles->tryServe($method, $target, $conn)) {
                $isStatic = true;
                $status   = 200;
                return;
            }

            $response = $this->handler->handle($request);
            $status   = $response->getStatusCode();
            $this->emitter->emit($response, $conn);
        } catch (Throwable $e) {
            $this->logger->error('Request error from ' . $peerName, ['exception' => $e]);
            if (is_resource($conn)) {
                $this->emitter->emitError($conn, 500, 'Internal Server Error');
            }
        } finally {
            if (is_resource($conn)) {
                fclose($conn);
            }

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
    }
}
