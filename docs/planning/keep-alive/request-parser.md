# Keep-Alive Refactor — RequestParser

## Current Behaviour

`parse()` returns `null` only when **nothing was received on the very first read** (empty
`$raw` on EOF). This covers speculative pre-connects. Any subsequent EOF mid-stream throws
a `RuntimeException`.

In a keep-alive loop the connection is legitimate and idle between requests. When the
client closes it between requests, `fread()` will return `''` or `false` on the first read
of the next iteration — this is **not** an error, it is a clean client-side close.

## Required Change

`parse()` must distinguish:

| Condition | Current | Target |
|-----------|---------|--------|
| EOF on first read, nothing received | `return null` ✅ | `return null` ✅ |
| EOF mid-headers (connection dropped) | `throw RuntimeException` ✅ | `throw RuntimeException` ✅ |
| EOF on first read after a prior request was served (keep-alive close) | `throw RuntimeException` ❌ | `return null` ✅ |

The current `$raw === ''` check already handles the third case correctly — the condition
is already `if ($raw === '')  return null`. Re-reading the code confirms **no change is
needed to `RequestParser`** — it already handles the idle-close case by returning `null`
whenever the first read yields no bytes, regardless of whether a previous request was
served on the same connection.

## Verification

Before implementation, add a unit test that calls `parse()` twice on the same socket
mock — first returning a valid request, then returning `''` (EOF) — and asserts that
the second call returns `null` rather than throwing.

## Signature

No signature change required:

```php
public function parse(mixed $socket, string $peerName): ?ServerRequestInterface
```

`null` = no request (connection closed cleanly or speculative pre-connect)  
`ServerRequestInterface` = a valid request to dispatch  
`throws RuntimeException` = malformed or truncated request
