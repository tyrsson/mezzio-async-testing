---
description: "Use when writing or reviewing any code that uses TrueAsync (php-async extension) primitives: spawn, await, Scope, Future, Channel, Context, Pool, TaskGroup, delay, suspend, protect, timeout. Also applies when handling coroutine cancellation, concurrency safety, or signal handling."
---

# TrueAsync Primitives Usage Guide

## Extension Check

Always guard integration tests and code paths that require the extension:

```php
if (!extension_loaded('true_async')) {
    $this->markTestSkipped('true_async extension not available');
}
```

---

## Spawning Coroutines

Use `spawn()` to create a coroutine. It is immediately scheduled but does not run until the
current coroutine yields. Always capture the return value if you need to await results or
cancel the coroutine.

```php
use function Async\spawn;

// Fire-and-forget (result discarded)
spawn(fn() => doBackgroundWork());

// With awaitable result
$coroutine = spawn(fn() => computeResult());
$result = await($coroutine);
```

**Never** use `spawn()` at the top level of a long-running server without an owning scope.
Unscoped coroutines attach to the global scope and may outlive their intended context.

---

## Scope: Structured Lifetime Management

Every group of coroutines with a shared lifetime must be owned by an `Async\Scope`.

```php
$scope = new Async\Scope();

$scope->setExceptionHandler(function(\Throwable $e) use ($logger) {
    $logger->error('Unhandled coroutine error', ['exception' => $e]);
});

$scope->spawn(fn() => task1());
$scope->spawn(fn() => task2());

$scope->awaitCompletion(timeout(30_000));
```

**Shutdown pattern** — cancel the scope to stop all owned coroutines:

```php
$scope->cancel();  // signals AsyncCancellation to all child coroutines
// then wait for graceful drain:
$scope->awaitAfterCancellation(errorHandler: fn($e) => log($e));
```

**Dispose pattern** — for immediate release:

```php
finally {
    $scope->dispose();  // cancel + close; no new coroutines can be added
}
```

---

## Awaiting Results

```php
// Single result
$result = await($coroutine);

// All results (does not throw on individual failure)
$results = await_all($c1, $c2, $c3);

// All results, throws CompositeException if any fail
$results = await_all_or_fail($c1, $c2, $c3);

// First success
$result = await_any($c1, $c2, $c3);
```

---

## Timeouts and Cancellation

Use `timeout(int $ms)` as a cancellation token. Pass it as the second argument to `await()`,
or directly to `Channel::recv()`, `Pool::acquire()`, `Scope::awaitCompletion()`.

```php
use function Async\timeout;

try {
    $result = await($coroutine, timeout(5_000));
} catch (Async\OperationCanceledException $e) {
    // 5-second deadline exceeded
}
```

**Requesting cancellation on a coroutine:**

```php
$coroutine->cancel();
// The coroutine receives AsyncCancellation at its next suspension point
```

**Handling cancellation inside a coroutine:**

```php
spawn(function() {
    try {
        while (true) {
            doWork();
            suspend();
        }
    } catch (Async\AsyncCancellation $e) {
        // clean up and exit gracefully
    } finally {
        releaseResources();  // ALWAYS runs, even on cancellation
    }
});
```

---

## protect() — Deferred Cancellation

Use `protect()` around operations that must not be interrupted mid-way:

```php
$result = protect(function() use ($db) {
    $db->beginTransaction();
    $db->exec('INSERT INTO events ...');
    $db->commit();
    return 'saved';
});
// Cancellation is deferred until after protect() returns
```

---

## Channels — Coroutine Communication

```php
$ch = new Async\Channel(100);  // buffered

// Producer coroutine
spawn(function() use ($ch) {
    foreach ($items as $item) {
        $ch->send($item, timeout(1_000));
    }
    $ch->close();
});

// Consumer coroutine
spawn(function() use ($ch) {
    foreach ($ch as $item) {
        process($item);
    }
});
```

**Never** share a Channel across coroutines without understanding whether you need
buffered or unbuffered (rendezvous) semantics.

---

## Context — Request-Scoped Data

Use `Async\Context` instead of static variables or superglobals to propagate request-scoped
values through coroutine hierarchies:

```php
// Set on the coroutine handling one request
$coroutine->getContext()->set('requestId', $requestId);

// Read anywhere downstream (inherits from parent coroutines)
$requestId = spawn(fn() => ...)->getContext()->find('requestId');
```

---

## Pool — Shared Resources

Never share a single database connection, Redis handle, or socket across concurrent
coroutines. Use `Async\Pool`:

```php
$pool = new Async\Pool(
    factory:    fn() => new PDO($dsn, $user, $pass),
    destructor: fn($pdo) => null,   // PDO closes on GC
    min: 2,
    max: 10,
    healthcheckInterval: 30_000,
);

$pdo = $pool->acquire(timeout: 3_000);
try {
    $stmt = $pdo->query('SELECT ...');
    // ...
} finally {
    $pool->release($pdo);
}
```

---

## suspend() and delay()

`suspend()` yields the current coroutine for one scheduler tick — use for cooperative
polling loops.

`delay(int $ms)` suspends for a given duration without blocking other coroutines.

```php
// Polling loop
while (checkCondition() === false) {
    delay(100);   // check every 100 ms
}
```

---

## Common Mistakes

| Mistake | Correct Pattern |
|---------|-----------------|
| `spawn()` without scope in server loop | Use `$serverScope->spawn(...)` |
| Sharing one PDO across coroutines | Use `Async\Pool` |
| Using `sleep()` thinking it blocks all | `sleep()` inside coroutine yields, not blocks process |
| Catching `Async\AsyncCancellation` and not re-throwing | Re-throw or exit the coroutine cleanly |
| Forgetting `finally` for socket/file close | Always `fclose($socket)` in `finally` |
| Using `stream_set_blocking($s, false)` manually | Not needed — TrueAsync does this automatically |
