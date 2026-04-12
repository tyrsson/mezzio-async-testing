<?php

declare(strict_types=1);

namespace Mezzio\Async\Http;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

use function fread;
use function str_contains;
use function strlen;
use function substr;

/**
 * Reads raw bytes from a TrueAsync socket into a PSR-7 ServerRequest.
 *
 * Strategy: accumulate chunks via fread() until the \r\n\r\n header terminator
 * is found, then hand the raw string to ServerRequestFactory for PSR-7 assembly.
 * Inside a TrueAsync coroutine fread() automatically yields to the scheduler
 * while waiting for data — no stream_set_timeout() or stream_set_blocking() needed.
 */
final readonly class RequestParser
{
    private const int READ_SIZE    = 8192;
    private const int MAX_SIZE     = 8_388_608; // 8 MiB hard ceiling

    public function __construct(
        private ServerRequestFactory $requestFactory,
    ) {}

    public function parse(mixed $socket, string $peerName): ?ServerRequestInterface
    {
        $raw   = '';
        $total = 0;

        // Read until we see the end of the HTTP headers
        while (true) {
            $chunk = fread($socket, self::READ_SIZE);

            if ($chunk === false || $chunk === '') {
                if ($raw === '') {
                    // Nothing received — speculative/keepalive connection, discard silently
                    return null;
                }
                throw new RuntimeException('Connection closed before complete request was received');
            }

            $raw   .= $chunk;
            $total += strlen($chunk);

            if ($total > self::MAX_SIZE) {
                throw new RuntimeException('Request exceeds maximum allowed size');
            }

            // Header block complete
            if (str_contains($raw, "\r\n\r\n")) {
                break;
            }
        }

        // Split headers and any body data already buffered
        $headerEnd   = strpos($raw, "\r\n\r\n");
        $headerBlock = substr($raw, 0, $headerEnd);
        $body        = substr($raw, $headerEnd + 4);

        // Parse request line
        $headerLines = explode("\r\n", $headerBlock);
        $requestLine = array_shift($headerLines);
        $parts       = explode(' ', $requestLine, 3);

        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed HTTP request line');
        }

        [$method, $target, $httpVersion] = $parts;
        $protocol = substr($httpVersion, 5); // strip 'HTTP/'

        // Parse headers
        $headers = [];
        foreach ($headerLines as $line) {
            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }
            $name              = strtolower(trim(substr($line, 0, $colonPos)));
            $value             = trim(substr($line, $colonPos + 1));
            $headers[$name][]  = $value;
        }

        // Read remaining body bytes declared by Content-Length
        $contentLength = (int) ($headers['content-length'][0] ?? 0);
        $remaining     = $contentLength - strlen($body);

        while ($remaining > 0) {
            $chunk = fread($socket, min($remaining, self::READ_SIZE));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body      .= $chunk;
            $remaining -= strlen($chunk);
        }

        return ($this->requestFactory)($method, $target, $protocol, $headers, $body, $peerName);
    }
}
