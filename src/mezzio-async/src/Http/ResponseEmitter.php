<?php

declare(strict_types=1);

namespace Mezzio\Async\Http;

use Psr\Http\Message\ResponseInterface;

use function fwrite;
use function sprintf;
use function strtolower;

/**
 * Serialises a PSR-7 Response to a TrueAsync socket.
 *
 * fwrite() inside a TrueAsync coroutine automatically yields to the scheduler
 * while waiting for the kernel send buffer — no explicit non-blocking setup needed.
 *
 * emit() returns true when the connection may be kept alive, false when the caller
 * must close it (Connection: close or HTTP/1.0 response).
 */
final readonly class ResponseEmitter
{
    /**
     * Emit a PSR-7 response to the socket.
     *
     * Injects Content-Length when not already present and the body size is known,
     * enabling HTTP keep-alive without chunked transfer encoding.
     *
     * @return bool true = keep connection alive, false = close after this response
     */
    public function emit(ResponseInterface $response, mixed $socket): bool
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        // Inject Content-Length when the pipeline has not set it and size is known.
        // Required for keep-alive — without it the client cannot determine message boundaries.
        if (! $response->hasHeader('Content-Length')) {
            $size = $body->getSize();
            if ($size !== null) {
                $response = $response->withHeader('Content-Length', (string) $size);
            }
        }

        // Use @ to suppress broken-pipe warnings — the return value tells us if the write failed.
        if (@fwrite($socket, sprintf(
            "HTTP/%s %d %s\r\n",
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
        )) === false) {
            return false;
        }

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                if (@fwrite($socket, "{$name}: {$value}\r\n") === false) {
                    return false;
                }
            }
        }

        if (@fwrite($socket, "\r\n") === false) {
            return false;
        }

        while (! $body->eof()) {
            if (@fwrite($socket, $body->read(65_536)) === false) {
                return false;
            }
        }

        // Signal whether the caller should keep the connection alive
        $connHeader = strtolower($response->getHeaderLine('Connection'));
        return $connHeader !== 'close' && $response->getProtocolVersion() !== '1.0';
    }

    public function emitError(mixed $socket, int $status, string $reason): void
    {
        @fwrite($socket, "HTTP/1.1 {$status} {$reason}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }
}
