# TrueAsync (php-async) API Reference

This document summarises the PHP TrueAsync extension API as it applies to `mezzio-async`
development. Canonical documentation: [TrueAsync documentation](https://true-async.github.io/en/docs.html)

> **PHP 8.6+ required.** All classes and functions live in the `Async\` namespace unless
> noted. The extension is identified by `extension_loaded('true_async')`.

---

## Core Paradigm

TrueAsync implements **transparent asynchrony without coloured functions**. There is no
`async`/`await` keyword distinction — normal PHP functions become non-blocking automatically
when called inside a coroutine. A single OS thread runs many coroutines cooperatively.

```text
Outside a coroutine: standard blocking PHP behaviour (unchanged)
Inside a coroutine:  I/O calls automatically yield to the scheduler
```

---

## Functions

### `spawn(callable $fn, mixed ...$args): Async\Coroutine`

Creates and immediately schedules a new coroutine. Returns the `Coroutine` object; does **not**
wait for it to complete.

```php
use function Async\spawn;

$coroutine = spawn(function() {
    $body = file_get_contents('https://api.example.com/users');
    return json_decode($body, true);
});
```

### `spawn_with(Async\SpawnStrategy $strategy, callable $fn, mixed ...$args): Async\Coroutine`

Same as `spawn()` but applies a custom `SpawnStrategy` (scheduling hook implementing
`beforeCoroutineEnqueue` / `afterCoroutineEnqueue`).

### `await(Async\Awaitable $awaitable): mixed`

Suspends the current coroutine until the awaitable completes and returns its result. Throws
if the awaitable was rejected.

```php
$result = await($coroutine);   // wait for a Coroutine
$value  = await($future);      // wait for a Future
```

### `await_all(Async\Awaitable ...$awaitables): array`

Waits for **all** awaitables to settle. Returns array of results indexed by argument order.
Does **not** throw on individual failures — failed awaitables return their exception in the
result array.

### `await_all_or_fail(array $triggers, ?Cancellation $cancellation = null, bool $preserveKeyOrder = false): array`

Waits for all to complete. Throws `Async\CompositeException` if **any** fail.

> **Note:** The actual runtime signature takes an **array** of awaitables as the first
> argument, not a variadic spread. Pass futures as `await_all_or_fail([$f1, $f2, $f3])`.

### `await_any(Async\Awaitable ...$awaitables): mixed`

Returns the result of the **first** successfully completed awaitable. Ignores failures
(as long as at least one succeeds).

### `await_any_or_fail(Async\Awaitable ...$awaitables): mixed`

Returns the result of the first to settle (success or failure). Throws if that first
result is an error.

### `await_first_success(Async\Awaitable ...$awaitables): mixed`

Returns the first **success**. If all fail, throws `Async\CompositeException`.

### `await_any_of(int $count, Async\Awaitable ...$awaitables): array`

Waits for `$count` awaitables to complete successfully.

### `await_any_of_or_fail(int $count, Async\Awaitable ...$awaitables): array`

Same as above but treats any error as a failure.

### `suspend(): void`

Explicitly yields control to the scheduler, allowing other coroutines to run. Resumes on
the next scheduler tick.

```php
spawn(function() {
    while (true) {
        processNextItem();
        suspend();  // cooperate — yield between items
    }
});
```

### `delay(int $ms): void`

Suspends the current coroutine for the specified number of milliseconds. Other coroutines
continue running during the delay.

```php
delay(1000); // sleep 1 second cooperatively
```

### `iterate(iterable $source): iterable`

Wraps an iterable (array, generator, Channel) for use in a `foreach` loop inside a coroutine,
yielding to the scheduler between iterations.

### `timeout(int $ms): Async\Timeout`

Creates a one-shot `Async\Timeout` that fires after `$ms` milliseconds. Use as a
cancellation token for `await()`, `Channel::recv()`, `Pool::acquire()`, etc.

```php
try {
    $result = await($coroutine, timeout(5000)); // 5-second deadline
} catch (Async\OperationCanceledException $e) {
    // timed out
}
```

### `protect(callable $fn): mixed`

Executes `$fn` in a protected section where cancellation is **deferred** until after the
function returns. Use for atomic critical sections (DB transactions, etc.).

---

## Classes

### `Async\Coroutine`

Represents a running or suspended coroutine. Created via `spawn()`.

**Key methods:**

```php
$c->getId(): int
$c->isQueued(): bool
$c->isStarted(): bool
$c->isRunning(): bool
$c->isSuspended(): bool
$c->isCompleted(): bool
$c->isCancelled(): bool
$c->isCancellationRequested(): bool
$c->cancel(?AsyncCancellation $reason = null): void
$c->getResult(): mixed          // throws if not completed
$c->getException(): mixed       // throws if still running
$c->finally(Closure $cb): void  // register completion callback
$c->getTrace(): ?array
$c->getSpawnLocation(): string
$c->getSuspendLocation(): string
$c->getAwaitingInfo(): array
$c->asHiPriority(): Coroutine   // elevate scheduler priority
```

**Lifecycle states:** Queued → Running → Suspended ↔ Running → Completed | Cancelled

### `Async\Scope`

Structured-concurrency container owning a group of coroutines. When a scope is cancelled or
disposed, all owned coroutines are cancelled recursively.

```php
$scope = new Async\Scope();                  // root scope
$child = Async\Scope::inherit();             // inherits from current scope
$child = Async\Scope::inherit($parentScope); // inherits from explicit parent

$scope->spawn(callable $fn, ...$args): Coroutine
$scope->cancel(?AsyncCancellation $reason = null): void
$scope->awaitCompletion(Awaitable $cancellation): void
$scope->awaitAfterCancellation(?callable $errorHandler, ?Awaitable $cancellation): void
$scope->dispose(): void          // cancel immediately, close scope
$scope->disposeSafely(): void    // close scope; existing coroutines finish as zombies
$scope->disposeAfterTimeout(int $ms): void
$scope->setExceptionHandler(callable $handler): void
$scope->setChildScopeExceptionHandler(callable $handler): void
$scope->finally(Closure $cb): void
$scope->isFinished(): bool
$scope->isClosed(): bool
$scope->isCancelled(): bool
$scope->getChildScopes(): array
```

**Global scope:** `Async\Scope::global()` — lives for the entire request / script lifetime.

### `Async\Future<T>` and `Async\FutureState<T>`

Promise-like pair. `FutureState` is the write side (resolve/reject); `Future` is the read side.

```php
$state = new Async\FutureState();
$future = new Async\Future($state);

// Resolve from any coroutine:
$state->complete($value);
$state->error(new \Exception('failed'));

// Await:
$result = await($future);
$result = $future->await(timeout(3000));

// Static factories:
$f = Async\Future::completed($value);
$f = Async\Future::failed($throwable);

// Chaining:
$mapped = $future->map(fn($v) => $v * 2);
$caught = $future->catch(fn($e) => 0);
$always = $future->finally(fn() => cleanup());
```

### `Async\Channel`

Typed FIFO message queue between coroutines.

```php
$ch = new Async\Channel(0);     // unbuffered (rendezvous)
$ch = new Async\Channel(100);   // buffered capacity 100

// Blocking send/receive (yield coroutine if full/empty):
$ch->send($value, ?Completable $cancel = null): void
$ch->recv(?Completable $cancel = null): mixed

// Non-blocking:
$ch->sendAsync($value): bool
$ch->recvAsync(): Future

// Lifecycle:
$ch->close(): void
$ch->isClosed(): bool
$ch->capacity(): int
$ch->count(): int

// Iterable — loops until close():
foreach ($ch as $item) { ... }
```

### `Async\Context`

Immutable key-value store inherited through the coroutine hierarchy. Each `spawn()` inherits
its parent's context.

```php
$ctx = $coroutine->getContext();

$ctx->find($key): mixed         // search current + ancestors
$ctx->get($key): mixed          // current only
$ctx->has($key): bool
$ctx->set($key, $value, replace: false): Context   // returns new instance
$ctx->unset($key): Context                          // returns new instance
```

**Use for:** request-scoped data (request ID, authenticated user) propagated without explicit
parameter passing through deep call stacks.

### `Async\Pool`

Generic async-aware resource pool.

```php
$pool = new Async\Pool(
    factory:             fn() => createResource(),
    destructor:          fn($r) => $r->close(),
    healthcheck:         fn($r) => $r->ping(),
    beforeAcquire:       fn($r) => $r->isValid(),
    beforeRelease:       fn($r) => !$r->isBroken(),
    min:                 2,
    max:                 10,
    healthcheckInterval: 30_000,     // ms
);

$resource = $pool->acquire(timeout: 5000);  // blocks coroutine if pool is exhausted
$resource = $pool->tryAcquire();            // returns null immediately if unavailable

try {
    doWork($resource);
} finally {
    $pool->release($resource);
}

$pool->count(): int
$pool->idleCount(): int
$pool->activeCount(): int
$pool->close(): void
```

### `Async\TaskGroup`

Parallel task execution with configurable concurrency and result aggregation.

```php
$group = new Async\TaskGroup(concurrency: 5);

$group->spawn(fn() => fetchUser(1));
$group->spawnWithKey('orders', fn() => fetchOrders(1));

$all     = await($group->all());    // wait for all
$first   = await($group->race());   // first to settle
$any     = await($group->any());    // first success

// Iterate as results arrive:
foreach ($group as $key => [$result, $error]) { ... }

$group->seal();              // no more tasks, allow awaitCompletion
$group->awaitCompletion();   // wait without throwing
$group->cancel(): void
$group->dispose(): void
```

### `Async\TaskSet`

Mutable worker-pool with automatic cleanup of completed tasks. Best for dynamic
fire-and-monitor patterns.

### `Async\Timeout`

One-shot `Completable` timer. Created via `timeout(int $ms)`.

```php
$t = timeout(5000);
await($coroutine, $t);                    // cancel await after 5s
$ch->recv($t);                            // cancel Channel recv after 5s
$scope->awaitCompletion($t);              // cancel scope wait after 5s
```

### `Async\FileSystemWatcher`

Watches filesystem paths for changes. Useful for hot-code-reload in development.

---

## Exception Hierarchy

```text
\Cancellation
  └─ Async\AsyncCancellation      — coroutine was cancelled
       └─ Async\OperationCanceledException  — token-triggered cancellation

\Exception
  └─ Async\AsyncException         — base async exception
       ├─ Async\ServiceUnavailableException — circuit breaker INACTIVE
       ├─ Async\ChannelException   — closed/exhausted channel
       └─ Async\PoolException      — closed/exhausted pool
  └─ Async\InputOutputException   — socket / file / pipe I/O error
  └─ Async\DnsException           — DNS resolution failure
  └─ Async\TimeoutException       — operation timed out
  └─ Async\PollException          — poll failure

\Error
  └─ Async\DeadlockError          — all coroutines blocked, no events pending
```

---

## INI Settings

| Directive               | Default | Scope        | Purpose                                    |
|-------------------------|---------|--------------|--------------------------------------------|
| `async.debug_deadlock`  | `1`     | PHP_INI_ALL  | Print deadlock diagnostic report on error  |

Disable in production if you want to suppress the verbose report:

```ini
async.debug_deadlock = 0
```

---

## Supported I/O Functions (Transparent Non-Blocking in Coroutines)

All functions below yield the current coroutine on I/O and resume when data is available.
Called outside a coroutine they behave as standard blocking PHP.

| Category          | Functions                                                                                                                        |
|-------------------|----------------------------------------------------------------------------------------------------------------------------------|
| Stream sockets    | `stream_socket_server`, `stream_socket_client`, `stream_socket_accept`, `stream_select`                                          |
| Raw sockets       | `socket_create`, `socket_connect`, `socket_accept`, `socket_read`, `socket_write`, `socket_send`, `socket_recv`, `socket_select` |
| File & stream I/O | `fopen`, `fread`, `fwrite`, `fgets`, `file_get_contents`, `file_put_contents`, `stream_get_contents`, `stream_copy_to_stream`    |
| cURL              | `curl_exec`, `curl_multi_exec`, `curl_multi_select`                                                                              |
| Databases         | `PDO` (MySQL, PgSQL), `mysqli_*`, `pg_*`                                                                                         |
| DNS               | `gethostbyname`, `gethostbyaddr`, `gethostbynamel`                                                                               |
| Timers            | `sleep`, `usleep`, `time_nanosleep`, `time_sleep_until`                                                                          |
| Process           | `proc_open`, `exec`, `shell_exec`                                                                                                |

**Not yet async** (metadata ops — block the coroutine briefly, not the whole process):
`opendir`, `readdir`, `unlink`, `rename`, `mkdir`, `rmdir`, `stat`, `lstat`

---

## Signal Handling

Signals are received within coroutines. The recommended approach for a server:

```php
spawn(function() use ($serverScope) {
    // TrueAsync provides signal handling at the coroutine level.
    // Use pcntl_signal() callbacks or the Async signal mechanism.
    pcntl_signal(SIGTERM, function() use ($serverScope) {
        $serverScope->cancel();
    });
    pcntl_signal(SIGUSR1, function() use ($runner) {
        $runner->reload();
    });
    while (true) {
        pcntl_signal_dispatch();
        suspend();
    }
});
```

> Details of TrueAsync's native signal coroutine API (if exposed) should be confirmed against
> the final extension release. The `Async\Signal` enum covers: SIGHUP, SIGINT, SIGQUIT,
> SIGTERM, SIGUSR1, SIGUSR2, SIGWINCH, etc.

---

## Concurrency Safety Notes

- **No mutex needed** for reading/writing PHP variables — coroutines share memory but execute
  cooperatively (only one runs at a time). No race conditions on plain PHP data.
- **One I/O resource per coroutine** — never share a socket, PDO connection, or file handle
  across concurrent coroutines. Use `Async\Pool` for shared resources.
- **`protect(callable)`** — defers cancellation through a critical section (e.g., DB
  transaction). Always wrap multi-step operations that must not be interrupted.
- **`finally` blocks run on cancellation** — guaranteed cleanup even when a coroutine is
  cancelled. Always close sockets/files in `finally`.
