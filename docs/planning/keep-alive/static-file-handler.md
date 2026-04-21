# Keep-Alive Refactor — StaticFileHandler

## Current Behaviour

`tryServe()` returns `bool` — `true` if it wrote a response, `false` if the request
should proceed to the Mezzio pipeline. When it returns `true` it has already written
a complete HTTP response including headers and body, but does **not** include a
`Connection` header or `Content-Length`. The connection is then closed by the `finally`
block in `AsyncRunner::handleConnection()`.

## Phase 1 — Break Loop, No Keep-Alive for Static Files

In the initial keep-alive implementation, static files **do not participate in
keep-alive**. When `tryServe()` returns `true`, the loop breaks and the connection is
closed. No changes to `StaticFileHandler` are required in Phase 1.

`AsyncRunner` handles this by setting `$keepAlive = false; break` immediately after
`tryServe()` returns `true`.

## Phase 2 — Static Files in Keep-Alive (future)

To allow static files to participate in keep-alive, `StaticFileHandler` needs to:

1. **Emit `Content-Length`** — file size is known via `filesize()`, so this is trivial.
2. **Emit `Connection` header** — accept a `bool $keepAlive` parameter and emit either
   `Connection: keep-alive` or `Connection: close`.
3. **Return connection disposition** — change return type or add an out-parameter so
   `AsyncRunner` knows whether to keep the connection alive.

### Proposed Phase 2 Signature

```php
/**
 * @return bool|null  true = served + keep-alive, false = served + close, null = not handled
 */
public function tryServe(string $method, string $path, mixed $conn, bool $keepAlive = false): ?bool
```

Or as a value object:

```php
public function tryServe(string $method, string $path, mixed $conn, bool $keepAlive): StaticServeResult
// StaticServeResult::NotHandled  → proceed to pipeline
// StaticServeResult::ServedClose → served, close connection
// StaticServeResult::ServedKeep  → served, keep connection alive
```

The value object approach is cleaner but introduces a new class. Decision deferred to
Phase 2 implementation.

## Phase 1 Checklist

- [ ] No changes required to `StaticFileHandler`
- [ ] `AsyncRunner` breaks the keep-alive loop on `tryServe()` returning `true`
- [ ] Existing `StaticFileHandler` unit tests pass without modification
