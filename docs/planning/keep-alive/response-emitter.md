# Keep-Alive Refactor — ResponseEmitter

## Current Behaviour

`emit()` writes status line, headers from the PSR-7 response, and the body.
It does **not** inject `Content-Length` or `Connection` headers — it relies entirely
on whatever headers the Mezzio pipeline places on the response.

`emitError()` always writes `Connection: close`.

## Problems for Keep-Alive

Without `Content-Length` (or `Transfer-Encoding: chunked`), the HTTP/1.1 client has no
way to know where one response ends and the next begins on a persistent connection.
It must read until the connection closes — making keep-alive impossible for responses
where the Mezzio pipeline does not set `Content-Length`.

## Required Changes

### 1. Inject `Content-Length` when not already set

If the response body is seekable and sized, compute its length and inject
`Content-Length` before writing headers. This is the common case for Laminas\Diactoros
responses (buffered streams).

```php
$body = $response->getBody();
if ($body->isSeekable()) {
    $body->rewind();
    $size = $body->getSize();
    if ($size !== null && ! $response->hasHeader('Content-Length')) {
        $response = $response->withHeader('Content-Length', (string) $size);
    }
}
```

### 2. Honour and propagate `Connection` header

The emitter must **not** inject its own `Connection` header — that is the loop's
responsibility (see `async-runner.md`). The loop decides whether to keep the connection
alive and passes the appropriate `Connection` header value down to the response before
calling `emit()`.

### 3. Return connection disposition

`emit()` should return a `bool` indicating whether the connection should be kept alive
after this response, allowing the loop in `AsyncRunner` to break cleanly:

```php
public function emit(ResponseInterface $response, mixed $socket): bool
// returns true  = keep connection alive
// returns false = close after this response
```

Disposition logic:
- `Connection: close` in the response → `false`
- HTTP/1.0 response → `false`  
- All other cases → `true`

### 4. `emitError()` — no change needed

Always emits `Connection: close`, which correctly terminates the keep-alive loop on error.

## Updated Signature

```php
public function emit(ResponseInterface $response, mixed $socket): bool;
public function emitError(mixed $socket, int $status, string $reason): void; // unchanged
```
