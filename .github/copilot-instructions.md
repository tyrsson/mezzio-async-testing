# mezzio-async Project Guidelines

## What This Project Is

`mezzio-async` provides a Mezzio framework integration for **PHP TrueAsync** (`php-async`
extension, PHP 8.6+). It enables Mezzio applications to run as long-lived CLI processes
with a coroutine-per-connection, non-blocking HTTP server — no Swoole, no FrankenPHP.

Reference implementation: `mezzio/mezzio-swoole` (attached workspace folder).  
Architecture planning: `docs/planning/` (read before implementing anything).  
TrueAsync API reference: `docs/planning/php-async-api.md`.

---

## Architecture

- **Single-process, single-thread, many coroutines.** There are no worker processes, no
  master/manager processes, and no task worker processes.
- **`AsyncRequestHandlerRunner`** is the central server class, implementing
  `RequestHandlerRunnerInterface`.
- **`Async\Scope`** owns all connection coroutines. Cancelling the scope shuts the server down.
- **PSR-14 events** are dispatched for every server lifecycle point. Keep the runner thin;
  put logic in listeners.
- **No FrankenPHP**, no Swoole. The HTTP server is built from PHP's native `stream_socket_server`
  / `stream_socket_accept` functions — which are transparent non-blocking inside TrueAsync coroutines.

See `docs/planning/overview.md` and `docs/planning/components.md` for full details.

---

## Code Conventions

### Namespace and Package
```
Package:   webware/mezzio-async
Namespace: Mezzio\Async
Config key: mezzio-async
CLI prefix: mezzio:async:*
```

### File Structure (mirrors mezzio-swoole)
```
src/
  ConfigProvider.php
  AsyncRequestHandlerRunner.php
  AsyncRequestHandlerRunnerFactory.php
  PidManager.php
  PidManagerFactory.php
  ServerRequestAsyncFactory.php
  Command/            Start, Stop, Reload, Status + factories
  Event/              Event classes + listeners + factories
  Http/               AsyncHttpParser, AsyncStream, AsyncResponseEmitter + factories
  StaticResourceHandler/
  HotCodeReload/
```

### PHP Style
- `declare(strict_types=1)` in every file
- Constructor property promotion for simple DTOs and factories
- `final` classes wherever inheritance is not explicitly designed for
- Typed properties throughout; no `mixed` unless required by interface contract
- Follow the existing `.php-cs-fixer.dist.php` config in the project root

### Dependency Injection
- Every service has a corresponding `*Factory` class
- Factories receive `Psr\Container\ContainerInterface $container` as sole argument
- No service locator anti-pattern — inject all dependencies via constructor
- Register everything in `ConfigProvider::getDependencies()`

### Testing
- PHPUnit 11 (`phpunit.xml.dist`)
- Test namespace: `MezzioTest\Async\`
- Test directory: `test/MezzioTest/Async/`
- All new classes must have unit tests
- Integration tests that require the `true_async` extension must be skipped when the
  extension is not loaded: `$this->markTestSkipped('true_async extension not available')`

---

## TrueAsync Usage Rules

1. **Never share I/O resources across concurrent coroutines.** Use `Async\Pool` for connections
   (PDO, Redis, etc.).
2. **All I/O is automatically non-blocking inside coroutines.** Do not use `stream_set_blocking`
   or event loops — TrueAsync handles this.
3. **`finally` blocks always run**, even on cancellation. Close sockets and file handles in `finally`.
4. **`protect(callable)`** — use for critical sections (DB transactions) where cancellation
   must be deferred.
5. **`Async\Scope`** — always scope coroutines with explicit lifetime management. Avoid
   `spawn()` at top-level without an owning scope.
6. **Services are stateful across requests.** Write all DI services to be stateless, or
   explicitly document and test any state they maintain.
7. **`Async\Context`** — use for request-scoped data propagation (request ID, auth user)
   instead of static variables or global state.

---

## What to Avoid

- Do **not** add Swoole-specific classes or type hints (`Swoole\*`)
- Do **not** use FrankenPHP APIs
- Do **not** use `async`/`await` keywords (PHP does not have them — this is not JavaScript)
- Do **not** use ReactPHP, Amp, or other userland async libraries — TrueAsync is built into
  the engine
- Do **not** create multi-process architectures — there is no master/worker split
- Do **not** call `pcntl_fork()` — not compatible with the TrueAsync scheduler

---

## Build and Test

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Static analysis
./vendor/bin/phpstan analyse

# Code style
./vendor/bin/php-cs-fixer fix --dry-run
```

## Key References

- `docs/planning/overview.md` — execution model and design decisions
- `docs/planning/components.md` — all components and their responsibilities
- `docs/planning/php-async-api.md` — TrueAsync API quick reference
- https://true-async.github.io/en/docs.html — canonical TrueAsync documentation
- Attached `mezzio-swoole/` workspace — the reference implementation to mirror
