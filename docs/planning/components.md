# mezzio-async: Component Breakdown

This document describes each component required to implement `mezzio-async`. The structure
mirrors `mezzio-swoole` closely to minimise the learning curve for developers already familiar
with that package.

---

## 1. `ConfigProvider`

**Class:** `Mezzio\Async\ConfigProvider`  
**Mirrors:** `Mezzio\Swoole\ConfigProvider`

Registers all DI services, event listeners, and console commands. Invoked by
`laminas-config-aggregator` during bootstrap.

**Responsibilities:**

- Register `AsyncRequestHandlerRunner` as the `RequestHandlerRunnerInterface` implementation
- Register all factory classes
- Register default `mezzio-async` configuration (host, port, options)
- Register `laminas-cli` console commands under the `mezzio:async:*` prefix

**Key config keys:**

```php
'mezzio-async' => [
    'http-server' => [
        'host'         => '127.0.0.1',
        'port'         => 8080,
        'backlog'      => 128,          // socket listen backlog
        'process-name' => 'mezzio-async',
    ],
    'hot-code-reload' => [
        'enabled'  => false,
        'interval' => 500,              // ms polling interval
        'paths'    => [],
    ],
]
```

---

## 2. `AsyncRequestHandlerRunner`

**Class:** `Mezzio\Async\AsyncRequestHandlerRunner`  
**Implements:** `Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface`  
**Mirrors:** `Mezzio\Swoole\SwooleRequestHandlerRunner`

The central orchestrator. Called by the Mezzio application runner to start the server.

**Responsibilities:**

- Create the root `Async\Scope` (server scope)
- Create a `Async\TaskSet` (connection set) to track all active TCP connections
- Create the TCP server socket via `stream_socket_server()`
- Spawn the accept-loop coroutine inside the server scope
- Spawn the signal-handler coroutine (SIGTERM, SIGINT, SIGUSR1)
- Dispatch PSR-14 lifecycle events (`ServerStartEvent`, `ServerShutdownEvent`)
- Block until the server scope completes (i.e., server shuts down)

**Connection tracking with `TaskSet`:**

Connections are tracked in a `Async\TaskSet` rather than being spawned directly into the
server scope. This gives three concrete benefits over a bare scope:

1. **Live connection count** — `$connectionSet->count()` feeds into `StatusCommand` metrics
2. **Clean drain** — `$connectionSet->seal()` + `$connectionSet->awaitCompletion()` is the
   graceful shutdown gate; no in-flight request is dropped
3. **Separation of concerns** — server scope lifetime (socket binding, signals) is distinct
   from connection coroutine lifetime (per-request I/O)

**Simplified pseudocode:**

```php
public function run(): void
{
    $serverScope     = new Async\Scope();
    $connectionSet   = new Async\TaskSet(); // tracks all active connections
    $socket          = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

    $serverScope->spawn(function() use ($socket, $connectionSet) {
        $this->dispatcher->dispatch(new ServerStartEvent());
        try {
            while (true) {
                $conn = @stream_socket_accept($socket, timeout: -1, $peerName);
                if ($conn === false) break;
                // Each connection is a task in the set — auto-removed on completion
                $connectionSet->spawn(fn() => $this->handleConnection($conn, $peerName));
            }
        } finally {
            fclose($socket);
        }
    });

    $serverScope->spawn(fn() => $this->handleSignals($serverScope, $connectionSet));
    $serverScope->awaitCompletion(timeout(PHP_INT_MAX));
    $this->dispatcher->dispatch(new ServerShutdownEvent());
}

private function shutdown(Async\Scope $serverScope, Async\TaskSet $connectionSet): void
{
    // Stop accepting new connections
    $serverScope->cancel();
    // Drain in-flight requests gracefully
    $connectionSet->seal();
    $connectionSet->awaitCompletion();
}
```

---

## 3. `AsyncHttpParser`

**Class:** `Mezzio\Async\Http\AsyncHttpParser`  
**No Swoole equivalent** (Swoole parses internally)

Parses raw HTTP/1.1 bytes from a socket stream into a PSR-7 `ServerRequestInterface`.

**Responsibilities:**

- Read the request line (method, URI, protocol version) from the socket
- Read headers until `\r\n\r\n`
- Determine body length from `Content-Length` or `Transfer-Encoding: chunked`
- Read body bytes
- Return a fully populated `Laminas\Diactoros\ServerRequest`

**Important constraints:**

- Must be non-blocking — all `fread()` calls yield the coroutine automatically in TrueAsync
- Must handle partial reads (TCP streaming)
- Must impose a configurable read timeout (use `stream_set_timeout()` — TrueAsync honours it)
- Does **not** need to handle HTTP/2 (out of scope for v1)

**Factory:** `Mezzio\Async\Http\AsyncHttpParserFactory`

---

## 4. `ServerRequestAsyncFactory`

**Class:** `Mezzio\Async\ServerRequestAsyncFactory`  
**Mirrors:** `Mezzio\Swoole\ServerRequestSwooleFactory`

A callable returned from the DI container that accepts a parsed request data array and
produces a `Laminas\Diactoros\ServerRequest` with correct `$_SERVER` equivalents populated.

**Responsibilities:**

- Normalise headers to SAPI format
- Apply `FilterServerRequestInterface` (XForwardedHeaders trust policy)
- Construct `Laminas\Diactoros\ServerRequest` with uploaded files, query params, body

---

## 5. `AsyncStream`

**Class:** `Mezzio\Async\Http\AsyncStream`  
**Implements:** `Psr\Http\Message\StreamInterface`  
**Mirrors:** `Mezzio\Swoole\SwooleStream`

Wraps the raw request body bytes (already fully buffered by the parser) as a PSR-7 stream.

**Note:** Because the parser reads the entire body before creating the PSR-7 request, this is
a simple in-memory stream backed by a string.

---

## 6. `AsyncResponseEmitter`

**Class:** `Mezzio\Async\Http\AsyncResponseEmitter`  
**Implements:** `Laminas\HttpHandlerRunner\Emitter\EmitterInterface`  
**Mirrors:** `Mezzio\Swoole\SwooleEmitter`

Serialises a PSR-7 `ResponseInterface` to raw HTTP/1.1 bytes and writes them to the
accepted socket resource.

**Responsibilities:**

- Write status line: `HTTP/1.1 {status} {reason}\r\n`
- Write headers (one per line)
- Write `\r\n` separator
- Write body in configurable chunks (default 2 MiB, same as mezzio-swoole)
- Call `fclose()` after emission unless `Connection: keep-alive` is negotiated (v2 concern)

**Factory:** `Mezzio\Async\Http\AsyncResponseEmitterFactory`

---

## 7. Event System

**Namespace:** `Mezzio\Async\Event`  
**Mirrors:** `Mezzio\Swoole\Event`

All events are typed value objects dispatched through a PSR-14 `EventDispatcherInterface`.

### Events

| Event Class              | Triggered When                                     | Swoole Equivalent      |
|--------------------------|----------------------------------------------------|------------------------|
| `ServerStartEvent`       | Accept loop begins, before first connection        | `ServerStartEvent`     |
| `ServerShutdownEvent`    | Accept loop ends, after all connections drained    | `ServerShutdownEvent`  |
| `RequestEvent`           | Each incoming HTTP request is accepted             | `RequestEvent`         |
| `RequestHandledEvent`    | PSR-7 response returned from pipeline              | (request listener)     |
| `ConnectionErrorEvent`   | Error reading/writing on a connection              | `WorkerErrorEvent`     |
| `BeforeReloadEvent`      | SIGUSR1 received, before draining connections      | `BeforeReloadEvent`    |
| `AfterReloadEvent`       | Reload complete, new accept loop running           | `AfterReloadEvent`     |

> **No manager/worker events.** TrueAsync is single-process — there are no separate manager
> or worker processes. The Swoole `ManagerStartEvent`, `WorkerStartEvent`, etc. have no
> equivalent and are not needed.

### Shipped Listeners

| Listener                             | Handles               | Purpose                                        |
|--------------------------------------|-----------------------|------------------------------------------------|
| `ServerStartListener`                | `ServerStartEvent`    | Log server start address/port                  |
| `ServerShutdownListener`             | `ServerShutdownEvent` | Log shutdown                                   |
| `RequestHandlerRequestListener`      | `RequestEvent`        | Dispatch request through Mezzio pipeline       |
| `StaticResourceRequestListener`      | `RequestEvent`        | Serve static assets before hitting pipeline    |
| `HotCodeReloaderListener`            | `AfterReloadEvent`    | Evict class/opcode caches after reload         |

---

## 8. Static Resource Handler

**Namespace:** `Mezzio\Async\StaticResourceHandler`  
**Mirrors:** `Mezzio\Swoole\StaticResourceHandler` (nearly identical)

Serves static files from the filesystem directly from within the accept loop coroutine,
bypassing the Mezzio middleware pipeline. Because `fread()` is coroutine-aware in TrueAsync,
no special async handling is needed beyond what the framework provides automatically.

---

## 9. Command Layer

**Namespace:** `Mezzio\Async\Command`  
**Mirrors:** `Mezzio\Swoole\Command`  
**CLI tool:** `laminas-cli`

| Command                  | CLI Invocation            | Purpose                                    |
|--------------------------|---------------------------|--------------------------------------------|
| `StartCommand`           | `mezzio:async:start`      | Start the async HTTP server                |
| `StopCommand`            | `mezzio:async:stop`       | Send SIGTERM to the running server         |
| `ReloadCommand`          | `mezzio:async:reload`     | Send SIGUSR1 for graceful hot-reload       |
| `StatusCommand`          | `mezzio:async:status`     | Report whether the server is running       |

All commands rely on `PidManager` to read/write the server PID.

---

## 10. `PidManager`

**Class:** `Mezzio\Async\PidManager`  
**Mirrors:** `Mezzio\Swoole\PidManager` (identical concept)

Reads and writes a PID file so that `stop`, `reload`, and `status` commands can signal the
running server process. Default path: `data/mezzio-async.pid`.

---

## 11. Hot Code Reload

**Namespace:** `Mezzio\Async\HotCodeReload`  
**Mirrors:** `Mezzio\Swoole\HotCodeReload`

Watches source paths for file changes (via `Async\FileSystemWatcher` if available, or a
polling fallback using `sleep()` inside a coroutine). On detecting a change, dispatches
`BeforeReloadEvent`, performs graceful shutdown of the accept loop (scope cancel with drain),
re-requires changed files, then restarts the accept loop. Dispatches `AfterReloadEvent`.

> **Note:** `Async\FileSystemWatcher` is available in TrueAsync. This is a native integration
> point that Swoole does not have — it is a significant improvement over Swoole's inotify-based
> approach.

---

## 12. Background Task Support

This is a significant simplification over the Swoole task worker model. There is no
`TaskEvent`, no `TaskFinishEvent`, no `task_worker_num` configuration.

### Background Task Dispatcher (fire-and-forget)

For simple fire-and-forget background work, a dedicated `Async\TaskSet` (rather than a bare
`Async\Scope`) is the preferred pattern. `TaskSet` gives a live task count and a clean
seal+drain path on shutdown, without requiring explicit error propagation.

```php
final class BackgroundTaskDispatcher
{
    private Async\TaskSet $taskSet;

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->taskSet = new Async\TaskSet();
        // Errors from background tasks are logged, not propagated
    }

    public function dispatch(callable $task): void
    {
        $this->taskSet->spawn(function() use ($task) {
            try {
                ($task)();
            } catch (Async\AsyncCancellation $e) {
                throw $e; // propagate cancellation so drain works
            } catch (\Throwable $e) {
                $this->logger->error('Background task failed', ['exception' => $e]);
            }
        });
    }

    /** Returns live count of running background tasks. */
    public function count(): int
    {
        return $this->taskSet->count();
    }

    /** Called during graceful shutdown to drain in-flight background tasks. */
    public function drain(int $timeoutMs = 30_000): void
    {
        $this->taskSet->seal();
        $this->taskSet->awaitCompletion(); // never throws; errors were caught above
    }
}
```

### Tracked Background Batches (with result/error collection)

When you need to dispatch a batch of background jobs and later confirm all succeeded
(e.g., sending a batch of webhooks), use `Async\TaskGroup`:

```php
$group = new Async\TaskGroup();

foreach ($webhooks as $url => $payload) {
    $group->spawnWithKey($url, fn() => sendWebhook($url, $payload));
}

$group->seal();

// Iterate results as they complete — log failures, retry successes
foreach ($group as $url => [$result, $error]) {
    if ($error !== null) {
        $logger->warning("Webhook failed: {$url}", ['error' => $error->getMessage()]);
    }
}
```

---

## 13. `TaskGroup` and `TaskSet` Usage Guide

TrueAsync provides two higher-level concurrency primitives that map cleanly onto distinct
roles in mezzio-async. Understanding which to use where is important.

### Decision Summary

| Primitive   | Fit                                                                 | mezzio-async use cases                                                |
|-------------|---------------------------------------------------------------------|-----------------------------------------------------------------------|
| `TaskSet`   | Unbounded, continuous stream of work; auto-cleanup of done tasks    | Connection tracking in accept loop; `BackgroundTaskDispatcher`        |
| `TaskGroup` | Bounded, named batch of parallel tasks; aggregate result strategies | Request-level fan-out inside handlers; startup initialisation batches |

---

### Use Case A — `TaskSet`: Connection Tracking (server layer)

The accept loop runs indefinitely, spawning one connection coroutine per TCP connection.
`TaskSet` is chosen over bare `Async\Scope` here because:

- **Live count** — `$connectionSet->count()` is the active connection count surfaced by `StatusCommand`
- **Clean drain** — on SIGTERM/SIGUSR1: `seal()` prevents new entries; `awaitCompletion()`
  blocks until every in-flight request finishes (no request is dropped)
- **Auto-cleanup** — completed connections are removed automatically; no bookkeeping

```php
// Accept loop adds to the set:
$connectionSet->spawn(fn() => $this->handleConnection($conn, $peerName));

// Graceful shutdown drain:
$connectionSet->seal();
$connectionSet->awaitCompletion(); // waits for zero active connections
```

---

### Use Case B — `TaskSet`: Background Task Dispatcher (server layer)

Fire-and-forget background work (emails, cache warming, audit logs) goes through a
`BackgroundTaskDispatcher` backed by a `TaskSet`. The `TaskSet` gives `count()` for
observability and the same `seal()` + `awaitCompletion()` drain on shutdown.

See the full implementation in section 12.

---

### Use Case C — `TaskGroup`: Request-Level Parallel Fan-out (handler layer)

When a single request handler needs results from multiple independent I/O operations,
`TaskGroup` is the recommended pattern. This is user code (inside a Mezzio handler or
middleware), not part of mezzio-async itself — but the package documentation and examples
should promote this pattern.

```php
// Inside a Mezzio RequestHandlerInterface::handle() implementation:
use function Async\await;

public function handle(ServerRequestInterface $request): ResponseInterface
{
    $id    = (int) $request->getAttribute('id');
    $group = new Async\TaskGroup();

    $group->spawnWithKey('user',        fn() => $this->users->find($id));
    $group->spawnWithKey('orders',      fn() => $this->orders->findByUser($id));
    $group->spawnWithKey('permissions', fn() => $this->permissions->getFor($id));
    $group->seal();

    // All three queries run concurrently; await all results
    $results = await($group->all());

    return new JsonResponse($results);
}
```

This replaces the Swoole task worker pattern entirely at the application level. Three
database queries that would take 3 × 50 ms = 150 ms sequentially complete in ~50 ms.

**`all()` vs `race()` vs `any()`:**

| Method  | Resolves When          | Use Case                                      |
|---------|------------------------|-----------------------------------------------|
| `all()` | All tasks complete     | Need every result (most common in handlers)   |
| `race()`| First to settle        | Timeout fallback, cache vs DB race            |
| `any()` | First **success**      | Multiple upstream sources, use whichever wins |

---

### Use Case D — `TaskGroup`: Startup Initialisation Batching (server layer)

During `ServerStartEvent`, listeners may need to prime resource pools, warm caches, and
pre-resolve routes in parallel. A `TaskGroup` orchestrates this cleanly and fails fast if
any critical initialisation step fails:

```php
// In a ServerStartListener:
$group = new Async\TaskGroup();
$group->spawn(fn() => $this->dbPool->warmUp(2));       // pre-create 2 DB connections
$group->spawn(fn() => $this->redisPool->warmUp(2));    // pre-create 2 Redis connections
$group->spawn(fn() => $this->router->compile());       // warm route cache
$group->seal();

await($group->all()); // throws CompositeException if any step fails
```

---

## 14. Connection Lifecycle and Statefulness

Because TrueAsync is single-process and single-thread, DI container services persist for the
entire lifetime of the server. The same stateless-service discipline from mezzio-swoole applies:

- **Do not accumulate state** in services between requests
- Template renderers, flashmessenger, authentication context — must be reset per request
- Use `Async\Context` to pass request-scoped data through the coroutine hierarchy instead of
  static variables or global state
- PSR-7 request and response objects are already immutable — safe
- PDO connections: use `Async\Pool` or the built-in PDO Pool — never share a single PDO
  instance across concurrent coroutines

---

## Component Dependency Map

```text
AsyncRequestHandlerRunner
  ├─ ConfigProvider (bootstraps DI)
  ├─ PidManager
  ├─ Async\TaskSet (connectionSet — tracks active TCP connections, drives drain)
  ├─ BackgroundTaskDispatcher (Async\TaskSet internally)
  ├─ EventDispatcherInterface (PSR-14)
  │     ├─ AsyncListenerProvider → all event listeners
  │     └─ ServerStartListener, ServerShutdownListener, RequestHandlerRequestListener, ...
  ├─ AsyncHttpParser → ServerRequestAsyncFactory → ServerRequest (PSR-7)
  ├─ RequestHandlerInterface (Mezzio pipeline) → ResponseInterface (PSR-7)
  ├─ AsyncResponseEmitter
  ├─ StaticResourceHandlerInterface
  └─ Command/* (start, stop, reload, status) → PidManager
```
