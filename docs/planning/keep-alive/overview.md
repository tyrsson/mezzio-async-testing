# HTTP Keep-Alive Refactor — Overview

## Motivation

Load testing (`max-rps-no-db-v2.js`, 2026-04-21) showed that the no-DB endpoint
processed 865k requests at a **4,113 req/s average** before hitting the 600 VU ceiling,
with `http_req_blocked` averaging **67ms** — over 10× the actual server processing time
(p95 8.29ms). The bottleneck is TCP connection establishment, not PHP or TrueAsync.

With HTTP/1.1 keep-alive, a single TCP connection serves multiple sequential requests.
This eliminates per-request TCP handshake overhead and should allow far fewer VUs to
drive far higher req/s throughput, exposing the true PHP/TrueAsync ceiling.

---

## Current Architecture (one connection = one request)

```
accept() → handleConnection() → parse() → dispatch() → emit() → fclose()
```

Each accepted TCP connection is handled by a single coroutine in `AsyncRunner::handleConnection()`.
The connection is **always closed in `finally`**. `RequestParser::parse()` reads exactly
one HTTP request and returns. `ResponseEmitter::emit()` writes the response and returns.
The `Monolog` logger is closed after each request via `$this->logger->close()`.

---

## Target Architecture (one connection = N requests)

```
accept() → handleConnection() → loop {
    parse() → dispatch() → emit() → [close if Connection: close | upgrade | error]
}
→ fclose() on loop exit
```

The connection coroutine loops, reading and serving requests until:
- The client sends `Connection: close`
- HTTP/1.0 (keep-alive not default)
- The response sets `Connection: close`
- A parse/dispatch/emit error occurs
- The `Async\Scope` is cancelled (server shutdown)

---

## Files to Change

| File | Change |
|------|--------|
| `Http/RequestParser.php` | Return `null` on EOF (idle keep-alive close), not only on empty first read |
| `Http/ResponseEmitter.php` | Emit `Content-Length` and `Connection` headers correctly |
| `Runner/AsyncRunner.php` | Replace single-request flow with keep-alive request loop |
| `Http/StaticFileHandler.php` | Return connection disposition so loop knows whether to close |

---

## Documents in This Directory

- `overview.md` — this file; motivation and summary
- `request-parser.md` — changes to `RequestParser`
- `response-emitter.md` — changes to `ResponseEmitter`
- `async-runner.md` — keep-alive loop in `AsyncRunner::handleConnection()`
- `static-file-handler.md` — disposition return value
- `k6-test-plan.md` — k6 scripts to validate keep-alive throughput
