<?php

declare(strict_types=1);

namespace Mezzio\Async\Http;

use function fwrite;
use function file_get_contents;
use function filesize;
use function is_file;
use function is_readable;
use function pathinfo;
use function realpath;
use function str_starts_with;
use function strlen;
use function strtolower;

use const PATHINFO_EXTENSION;

/**
 * Serves files from the public/ directory for GET/HEAD requests.
 *
 * Returns true and writes the response if the path resolves to a real file.
 * Returns false if the request should be forwarded to the Mezzio pipeline.
 *
 * Path traversal is prevented by verifying the resolved path starts with
 * the public root.
 */
final class StaticFileHandler
{
    private const array MIME_TYPES = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'json'  => 'application/json',
        'html'  => 'text/html; charset=utf-8',
        'htm'   => 'text/html; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'txt'   => 'text/plain; charset=utf-8',
    ];

    private string $publicRoot;

    public function __construct(string $publicRoot)
    {
        $this->publicRoot = realpath($publicRoot) ?: $publicRoot;
    }

    /**
     * Attempt to serve a static file. Returns true if the response was sent,
     * false if the request should continue to the Mezzio pipeline.
     *
     * @param mixed $conn Socket resource
     */
    public function tryServe(string $method, string $path, mixed $conn): bool
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        // Strip query string
        $cleanPath = strtok($path, '?');
        if ($cleanPath === false || $cleanPath === '/') {
            return false;
        }

        $candidate = realpath($this->publicRoot . $cleanPath);

        // Reject path traversal and non-files
        if (
            $candidate === false
            || ! str_starts_with($candidate, $this->publicRoot)
            || ! is_file($candidate)
            || ! is_readable($candidate)
        ) {
            return false;
        }

        $ext      = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        $mime     = self::MIME_TYPES[$ext] ?? 'application/octet-stream';
        $size     = filesize($candidate);
        $body     = $method === 'HEAD' ? '' : (file_get_contents($candidate) ?: '');
        $bodyLen  = $method === 'HEAD' ? $size : strlen($body);

        $headers  = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: {$mime}\r\n"
            . "Content-Length: {$bodyLen}\r\n"
            . "Cache-Control: public, max-age=3600\r\n"
            . "Connection: close\r\n"
            . "\r\n";

        fwrite($conn, $headers . $body);

        return true;
    }
}
