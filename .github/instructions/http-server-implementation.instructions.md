---
description: "Use when implementing or reviewing the HTTP server layer: AsyncRequestHandlerRunner, AsyncHttpParser, AsyncStream, AsyncResponseEmitter, socket accept loop, HTTP/1.1 request parsing, response serialisation, connection handling, or socket resource management."
applyTo: "src/Http/**/*.php"
---

# HTTP Server Implementation Guide

## Server Socket Setup

The server socket is created with `stream_socket_server()`. TrueAsync makes `stream_socket_accept()`
non-blocking automatically inside a coroutine — no `stream_set_blocking()` calls needed.

```php
$context = stream_context_create(['socket' => ['backlog' => $backlog]]);
$socket  = stream_socket_server(
    "tcp://{$host}:{$port}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context,
);

if ($socket === false) {
    throw new RuntimeException("Cannot bind {$host}:{$port}: {$errstr} ({$errno})");
}
```

**Close the socket in `finally`:**

```php
try {
    // accept loop
} finally {
    fclose($socket);
}
```

---

## Accept Loop

The accept loop runs in a coroutine owned by the server scope. Each accepted connection spawns
its own child coroutine. The loop runs until the scope is cancelled.

```php
$scope->spawn(function() use ($socket, $scope) {
    while (true) {
        $conn = @stream_socket_accept($socket, timeout: -1, $peerName);
        if ($conn === false) {
            // accept was interrupted (e.g. scope cancelled) — exit cleanly
            break;
        }
        // Each connection is isolated in its own coroutine
        $scope->spawn(function() use ($conn, $peerName) {
            try {
                $this->handleConnection($conn, $peerName);
            } finally {
                fclose($conn);
            }
        });
    }
});
```

**Key points:**
- `stream_socket_accept(..., timeout: -1)` blocks only the accept coroutine, not the process
- `@` suppresses the warning emitted when accept is interrupted by cancellation
- Each connection coroutine owns its socket and MUST `fclose()` it in `finally`
- The connection scope is separate from the server scope; cancel only the connection coroutine
  on per-connection errors, not the entire server scope

---

## HTTP/1.1 Request Parsing (`AsyncHttpParser`)

The parser reads raw bytes from the socket and produces a `ServerRequestInterface`. All
`fread()` and `fgets()` calls inside a coroutine automatically yield to the scheduler.

### Parsing Strategy

1. **Read request line** — `METHOD SP Request-URI SP HTTP/Version CRLF`
2. **Read headers** — read lines until empty line (`\r\n`)
3. **Determine body length** from `Content-Length` header or `Transfer-Encoding: chunked`
4. **Read body** — either fixed-length or de-chunked

### Implementation Sketch

```php
public function parse(mixed $socket): RawRequest
{
    // Set a configurable read timeout
    stream_set_timeout($socket, $this->readTimeoutSeconds);

    // 1. Read request line
    $requestLine = fgets($socket, 8192);
    if ($requestLine === false) {
        throw new InputOutputException('Failed to read request line');
    }
    [$method, $target, $protocol] = explode(' ', rtrim($requestLine), 3);

    // 2. Read headers
    $headers = [];
    while (($line = fgets($socket, 8192)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') break;
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))][] = trim($value);
    }

    // 3. Read body
    $body = $this->readBody($socket, $headers);

    return new RawRequest($method, $target, $protocol, $headers, $body);
}
```

### Timeout Handling

`stream_set_timeout()` is honoured by TrueAsync inside coroutines. After calling it, check
`stream_get_meta_data($socket)['timed_out']` after each read call, or rely on the `-1`
return value and throw `Async\TimeoutException`.

### Limits to Enforce (Configurable)

| Limit | Config Key | Default |
|-------|-----------|---------|
| Max request line length | `max-request-line` | 8 KiB |
| Max header line length | `max-header-line` | 8 KiB |
| Max header count | `max-headers` | 100 |
| Max body size | `max-body-size` | 8 MiB |
| Read timeout | `read-timeout` | 30 s |

---

## `AsyncStream` (PSR-7 Body)

The parser fully buffers the request body before building the PSR-7 request. Wrap it in a
simple string-backed stream:

```php
final class AsyncStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private string $content) {}

    public function getContents(): string { return substr($this->content, $this->position); }
    public function read(int $length): string { /* substr + advance position */ }
    public function rewind(): void { $this->position = 0; }
    public function isReadable(): bool { return true; }
    public function isWritable(): bool { return false; }
    public function isSeekable(): bool { return true; }
    // ... remaining PSR-7 StreamInterface methods
}
```

Do **not** stream the socket body through the PSR-7 stream lazily — it complicates
coroutine lifetime management. Read it fully upfront.

---

## `AsyncResponseEmitter`

Serialises a PSR-7 `ResponseInterface` to raw HTTP/1.1 bytes.

```php
public function emit(ResponseInterface $response, mixed $socket): void
{
    // Status line
    fwrite($socket, sprintf(
        "HTTP/%s %d %s\r\n",
        $response->getProtocolVersion(),
        $response->getStatusCode(),
        $response->getReasonPhrase(),
    ));

    // Headers
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            fwrite($socket, "{$name}: {$value}\r\n");
        }
    }
    fwrite($socket, "\r\n");

    // Body in chunks
    $body = $response->getBody();
    if ($body->isSeekable()) {
        $body->rewind();
    }
    while (!$body->eof()) {
        fwrite($socket, $body->read(self::CHUNK_SIZE));
    }
}

public const CHUNK_SIZE = 2_097_152; // 2 MiB, same as mezzio-swoole
```

**All `fwrite()` calls yield the coroutine automatically on slow clients.** No manual async
wiring needed.

---

## Error Handling in Connection Coroutines

Connection coroutines must not propagate exceptions to the server scope — they must catch
and log locally:

```php
$scope->spawn(function() use ($conn, $peerName) {
    try {
        $request  = $this->parser->parse($conn);
        $psrReq   = ($this->requestFactory)($request);
        $this->dispatcher->dispatch(new RequestEvent($psrReq, $conn));
    } catch (Async\AsyncCancellation $e) {
        // Server is shutting down — do nothing, let finally close the socket
        throw $e;   // re-throw so scope knows coroutine was cancelled
    } catch (\Throwable $e) {
        $this->dispatcher->dispatch(new ConnectionErrorEvent($e, $peerName));
        $this->emitErrorResponse($conn, 500);
    } finally {
        fclose($conn);
    }
});
```

**Rule:** Only re-throw `Async\AsyncCancellation`. Swallow or handle all other exceptions
at the connection boundary. Never let a single bad request bring down the entire server.

---

## PSR-7 Request Construction

Use `ServerRequestAsyncFactory` to build the `Laminas\Diactoros\ServerRequest`. Populate
`$_SERVER`-equivalent keys from the parsed raw request:

```php
$serverParams = [
    'REQUEST_METHOD'  => $raw->method,
    'REQUEST_URI'     => $raw->target,
    'SERVER_PROTOCOL' => $raw->protocol,
    'REMOTE_ADDR'     => $peerName,
    'HTTP_HOST'       => $raw->headers['host'][0] ?? '',
    // ... other SAPI equivalents
];
```

Apply `FilterServerRequestInterface` (XForwardedHeaders) the same way
`ServerRequestSwooleFactory` does in mezzio-swoole.

---

## HTTP Keep-Alive (v2 Concern)

HTTP/1.1 persistent connections (`Connection: keep-alive`) are **out of scope for v1**. In
v1, every connection is closed after the response is sent. Set `Connection: close` in all
responses to ensure clients do not expect keep-alive.

---

## Chunked Transfer Encoding (Reading)

If `Transfer-Encoding: chunked` is present in the request:

```
chunk-size CRLF
chunk-data CRLF
...
0 CRLF
CRLF
```

De-chunk the body before passing it to the PSR-7 stream. Reference: RFC 7230 §4.1.

---

## Static Files

For static file serving, `fread()` and `fwrite()` inside coroutines are automatically
non-blocking. Serve files with a read-file-write-socket loop:

```php
$file = fopen($filePath, 'rb');
try {
    while (!feof($file)) {
        fwrite($socket, fread($file, self::CHUNK_SIZE));
    }
} finally {
    fclose($file);
}
```

No extra async machinery required.
