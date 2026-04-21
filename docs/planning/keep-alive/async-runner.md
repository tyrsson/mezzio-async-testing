# Keep-Alive Refactor — AsyncRunner

## Current Behaviour

`handleConnection()` processes exactly one request per TCP connection:

```
parse → dispatch → emit → fclose (always, in finally)
```

The logger is closed after the single request. The connection is always closed in
`finally` regardless of `Connection` header.

## Target Behaviour

`handleConnection()` loops until the connection should be closed:

```
loop:
  parse → [null = break cleanly]
  dispatch → emit → [Connection: close | error = break]
fclose (after loop exits)
logger close (after loop exits, once per connection)
```

## Proposed Implementation

```php
private function handleConnection(mixed $conn, string $peerName): void
{
    try {
        while (true) {
            // parse() returns null on clean EOF (client closed idle connection)
            try {
                $request = $this->parser->parse($conn, $peerName);
            } catch (Throwable $e) {
                $this->logger->warning('Parse error from ' . $peerName, ['exception' => $e]);
                break;
            }

            if ($request === null) {
                // Clean close — speculative pre-connect or keep-alive idle timeout
                break;
            }

            $method   = $request->getMethod();
            $target   = $request->getRequestTarget();
            $startNs  = hrtime(true);
            $status   = 500;
            $keepAlive = true;

            try {
                // Determine keep-alive disposition from request
                $connHeader = strtolower($request->getHeaderLine('Connection'));
                $isHttp10   = $request->getProtocolVersion() === '1.0';
                $keepAlive  = ! $isHttp10 && $connHeader !== 'close';

                if ($this->staticFiles->tryServe($method, $target, $conn)) {
                    $status    = 200;
                    // Static file handler always closes — it writes its own response
                    $keepAlive = false;
                    break;
                }

                // Inject Connection header before dispatch so pipeline can read it
                $response = $this->handler->handle($request);
                $status   = $response->getStatusCode();

                // Add Connection header to response matching our disposition
                $response = $response->withHeader(
                    'Connection',
                    $keepAlive ? 'keep-alive' : 'close'
                );

                // emit() returns false if the response signals close
                $keepAlive = $this->emitter->emit($response, $conn) && $keepAlive;
            } catch (Throwable $e) {
                $this->logger->error('Request error from ' . $peerName, ['exception' => $e]);
                if (is_resource($conn)) {
                    $this->emitter->emitError($conn, 500, 'Internal Server Error');
                }
                break;
            } finally {
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

            if (! $keepAlive) {
                break;
            }
        }
    } finally {
        // Always close and flush logger once per connection (not per request)
        if (is_resource($conn)) {
            fclose($conn);
        }
        $this->logger->close();
    }
}
```

## Key Differences from Current Code

| Aspect | Current | Keep-Alive |
|--------|---------|------------|
| `fclose()` location | `finally` of single request | `finally` of connection loop |
| `logger->close()` | After each non-static request | Once per connection, after loop |
| Parse `null` | Closes connection | Breaks loop cleanly |
| Parse exception | Logs warning, closes | Logs warning, breaks loop |
| Dispatch exception | Logs error, emits 500, closes | Logs error, emits 500, breaks loop |
| Static file | Returns, closes in finally | Breaks loop (static handler manages own close) |
| `Connection: close` request/response | N/A (always closed) | Breaks loop after response |

## Logger Close — Timing Change

Currently `logger->close()` runs after every non-static request. Under keep-alive it
runs once after the connection closes. Monolog `StreamHandler` buffers nothing by default
(`useLocking: false`) so log entries are flushed immediately on each `logger->info()` /
`logger->error()` call — closing the handler just releases the file descriptor.

**Impact:** Under keep-alive with many requests per connection, the file descriptor stays
open for the connection lifetime. This is expected and safe — Monolog re-opens it if closed.

## Static File Handler — Disposition

The static file handler currently writes its own complete response including
`Connection: close` implicitly (no `Connection` header, then `fclose` in `finally`).

In the keep-alive loop, `tryServe()` returning `true` must break the loop immediately —
the static handler cannot know the keep-alive context. This is handled above by
`$keepAlive = false; break` after `tryServe()` returns `true`.

A future improvement could allow static files to participate in keep-alive, but that
requires `StaticFileHandler` to also emit `Content-Length` and `Connection: keep-alive`.
See `static-file-handler.md` for that plan.
