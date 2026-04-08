# mezzio-async: High-Level Architecture Overview

## Purpose

`mezzio-async` provides a Mezzio framework integration for **PHP TrueAsync** (`php-async` extension),
enabling Mezzio applications to run as long-lived, high-concurrency CLI processes without an
external web server. The design mirrors `mezzio-swoole` closely, replacing Swoole's multi-process
HTTP server with a TrueAsync coroutine-per-connection model backed by PHP's native socket layer.

> **Note:** This project targets PHP 8.6+ with the `true_async` extension installed. No FrankenPHP,
> no Swoole. All I/O is handled by TrueAsync's transparent non-blocking coroutine scheduler.

---

## Execution Model Comparison

| Aspect               | mezzio-swoole                                         | mezzio-async (this project)                               |
|----------------------|-------------------------------------------------------|-----------------------------------------------------------|
| Server engine        | `Swoole\Http\Server` (PECL ext)                       | Custom PHP stream-socket server + TrueAsync coroutines    |
| Concurrency model    | Multi-process worker pool (master + N workers)        | Single-process, single-thread, N coroutines               |
| Request isolation    | Worker process boundary                               | Coroutine boundary (shared memory — statefulness matters) |
| Task offload         | Separate task worker processes + `$server->task()`    | `spawn()` a coroutine from within the request handler     |
| Entry point          | `./vendor/bin/laminas mezzio:swoole:start`            | `./vendor/bin/laminas mezzio:async:start`                 |
| Process management   | Master PID + worker PIDs                              | Single PID file                                           |
| Hot reload           | `SIGUSR1` → manager restarts workers                  | `SIGUSR1` → drain in-flight, restart accept loop          |
| Static file serving  | `StaticResourceHandler`                               | Same pattern — `StaticResourceHandler`                    |

---

## Architecture at a Glance

```text
CLI entry (bin/laminas mezzio:async:start)
    │
    └─► AsyncRequestHandlerRunner::run()
            │
            ├─► Async\Scope (server scope — owns all coroutines)
            │       │
            │       ├─► Accept loop coroutine
            │       │       stream_socket_server() → stream_socket_accept() per connection
            │       │       └─► spawn() connection coroutine per accepted socket
            │       │               ├─► AsyncHttpParser::parse($socket) → PSR-7 ServerRequest
            │       │               ├─► $handler->handle($request) (Mezzio pipeline)
            │       │               ├─► AsyncResponseEmitter::emit($response, $socket)
            │       │               └─► fclose($socket)
            │       │
            │       └─► Signal coroutine
            │               Listens for SIGTERM / SIGINT / SIGUSR1
            │               SIGTERM/SIGINT → $serverScope->cancel()
            │               SIGUSR1 → graceful reload
            │
            └─► PSR-14 EventDispatcher (lifecycle events throughout)
```

---

## Key Design Principles

### 1. Coroutine-per-connection

Each accepted TCP connection is handled inside its own `Async\Coroutine`, spawned inside a
managed `Async\Scope`. This means hundreds of simultaneous connections run concurrently in a
single OS thread. All PHP socket/stream functions (`stream_socket_accept`, `fread`, `fwrite`,
etc.) automatically yield the coroutine to the scheduler when awaiting I/O.

### 2. Structured Concurrency via Scope

The entire server lifecycle is owned by a root `Async\Scope`. Shutting down the server means
calling `$serverScope->cancel()` — all in-flight request coroutines receive
`Async\AsyncCancellation`, can `finally` clean up resources, and then terminate.

### 3. PSR-14 Events (same pattern as mezzio-swoole)

`AsyncRequestHandlerRunner` dispatches typed PSR-14 events for every server lifecycle moment.
Each event is handled by registered listeners in the DI container. This keeps the runner thin
and listener logic composable.

### 4. No Shared Mutable State in Services

TrueAsync runs all coroutines in a single thread. Shared state in DI services is safe from
data races but **persists between requests** (same as Swoole). The same stateless-service
discipline documented in mezzio-swoole applies here. See `docs/planning/components.md` for details.

### 5. PSR-7 Bridge

Incoming raw HTTP/1.1 bytes are parsed into a `Laminas\Diactoros\ServerRequest`. The outgoing
PSR-7 `ResponseInterface` is serialised back to raw HTTP/1.1 bytes and written to the socket.
No third-party HTTP parsing library is assumed — the parser is part of this package.

---

## Runtime Requirements

| Requirement                       | Detail                                          |
|-----------------------------------|-------------------------------------------------|
| PHP version                       | 8.6+                                            |
| PHP extension                     | `true_async` (TrueAsync / php-async)            |
| Thread safety (ZTS)               | Required by TrueAsync                           |
| OS                                | Linux / macOS (Windows: limited stream support) |
| laminas/laminas-diactoros         | PSR-7 implementation                            |
| mezzio/mezzio                     | Application pipeline                            |
| laminas/laminas-httphandlerrunner | `RequestHandlerRunnerInterface`                 |
| PSR-14 event dispatcher           | e.g., `phly/phly-event-dispatcher`              |

---

## Package Identity

```text
Package name : webware/mezzio-async
Namespace    : Mezzio\Async
Config key   : mezzio-async
CLI prefix   : mezzio:async:*
```

---

## Further Reading

- [docs/planning/components.md](components.md) — detailed breakdown of each component
- [docs/planning/php-async-api.md](php-async-api.md) — TrueAsync primitives reference
- [TrueAsync documentation](https://true-async.github.io/en/docs.html)
- [mezzio-swoole v4 docs](../../vendor/mezzio-swoole/docs/book/v4/intro.md) (reference implementation)
