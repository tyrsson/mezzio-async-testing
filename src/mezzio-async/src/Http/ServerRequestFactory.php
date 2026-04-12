<?php

declare(strict_types=1);

namespace Mezzio\Async\Http;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\Uri;
use Psr\Http\Message\ServerRequestInterface;

use function parse_str;
use function parse_url;
use function str_contains;
use function str_replace;
use function strtoupper;

use const PHP_URL_QUERY;

/**
 * Builds a Laminas\Diactoros\ServerRequest from raw parsed HTTP data.
 *
 * This class is invokable so it can act directly as the callable factory passed
 * to RequestParser — no interface indirection needed at this stage.
 */
final class ServerRequestFactory
{
    /**
     * @param array<string, string[]> $headers
     */
    public function __invoke(
        string $method,
        string $target,
        string $protocol,
        array  $headers,
        string $body,
        string $peerName,
    ): ServerRequestInterface {
        $serverParams = [
            'SERVER_PROTOCOL' => 'HTTP/' . $protocol,
            'REQUEST_METHOD'  => strtoupper($method),
            'REQUEST_URI'     => $target,
            'REMOTE_ADDR'     => $peerName,
        ];

        $hostHeader = $headers['host'][0] ?? 'localhost';
        $uri        = new Uri('http://' . $hostHeader . $target);

        // Cookies
        $cookies = [];
        $cookieHeader = $headers['cookie'][0] ?? '';
        if ($cookieHeader !== '') {
            parse_str(str_replace(['; ', ';'], '&', $cookieHeader), $cookies);
        }

        // Query params
        $queryParams = [];
        $queryString = (string) parse_url($target, PHP_URL_QUERY);
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
        }

        // Parsed body for form submissions
        $parsedBody  = null;
        $contentType = $headers['content-type'][0] ?? '';
        if ($body !== '' && str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $parsedBody);
        }

        $bodyStream = new Stream('php://temp', 'wb+');
        $bodyStream->write($body);
        $bodyStream->rewind();

        return new ServerRequest(
            serverParams:  $serverParams,
            uploadedFiles: [],
            uri:           $uri,
            method:        strtoupper($method),
            body:          $bodyStream,
            headers:       $headers,
            cookieParams:  $cookies,
            queryParams:   $queryParams,
            parsedBody:    $parsedBody,
            protocol:      $protocol,
        );
    }
}
