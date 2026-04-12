<?php

declare(strict_types=1);

namespace Mezzio\Async\Http;

use Psr\Http\Message\ResponseInterface;

use function fwrite;
use function sprintf;

/**
 * Serialises a PSR-7 Response to a TrueAsync socket.
 *
 * fwrite() inside a TrueAsync coroutine automatically yields to the scheduler
 * while waiting for the kernel send buffer — no explicit non-blocking setup needed.
 */
final readonly class ResponseEmitter
{
    public function emit(ResponseInterface $response, mixed $socket): void
    {
        fwrite($socket, sprintf(
            "HTTP/%s %d %s\r\n",
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
        ));

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                fwrite($socket, "{$name}: {$value}\r\n");
            }
        }

        fwrite($socket, "\r\n");

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (! $body->eof()) {
            fwrite($socket, $body->read(65_536));
        }
    }

    public function emitError(mixed $socket, int $status, string $reason): void
    {
        fwrite($socket, "HTTP/1.1 {$status} {$reason}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }
}
