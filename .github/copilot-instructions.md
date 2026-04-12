# mezzio-async Project Guidelines

## What This Project Is

`mezzio-async` provides a Mezzio framework integration for **PHP TrueAsync** (`php-async`
extension, PHP 8.6+). It enables Mezzio applications to run as long-lived CLI processes
with a coroutine-per-connection, non-blocking HTTP server — no Swoole, no FrankenPHP.

TrueAsync API reference: `docs/planning/php-async-api.md`.

---

## Current Implementation Status

The core server is **working end-to-end**. The following components exist in
`src/mezzio-async/src/`:

```
ConfigProvider.php                 ← DI entry point (CLI-only dependencies)
Runner/
  AsyncRunner.php                  ← implements RequestHandlerRunnerInterface; handles connections
  AsyncRunnerFactory.php
Http/
  Server.php                       ← TCP socket, scheduler entry, Scope, accept loop, signals
  ServerFactory.php
  RequestParser.php                ← fread-based chunk accumulator → PSR-7
  RequestParserFactory.php
  ResponseEmitter.php              ← fwrite-based HTTP/1.1 serialiser
  ResponseEmitterFactory.php
  ServerRequestFactory.php         ← invokable; builds Laminas\Diactoros\ServerRequest
  ServerRequestFactoryFactory.php
  StaticFileHandler.php            ← serves public/ assets, path traversal protected
  StaticFileHandlerFactory.php
Log/
  LoggerDelegator.php              ← adds StreamHandlers to file + stderr
```

**Not yet implemented** (planned, do not fabricate):
- `Command/` (Start, Stop, Reload, Status)
- `Event/` (PSR-14 lifecycle events and listeners)
- `PidManager`
- `HotCodeReload/`

---

## Architecture

- **Single-process, single-thread, many coroutines.** No worker processes, no master/manager.
- **`Http\Server`** owns the TCP socket, TrueAsync scheduler entry, `Async\Scope` lifecycle,
  accept loop, and signal handling. It exposes a single `listen(callable $connectionHandler)` method.
- **`AsyncRunner`** is a thin Mezzio integration layer. `run()` calls `$this->server->listen($this->handleConnection(...))`. All connection logic (parse, dispatch, emit, log) lives in `handleConnection()`.
- **`Async\Scope`** owns all connection coroutines. Cancelling the scope shuts the server down.
- **No FrankenPHP**, no Swoole. The HTTP server uses PHP's native `stream_socket_server` /
  `stream_socket_accept` — automatically non-blocking inside TrueAsync coroutines.
- **`await(spawn(...))`** is required to enter the TrueAsync scheduler from the CLI entry
  point. The entire server lifecycle runs inside `Http\Server::listen()`.

---

## Code Conventions

### Namespace and Package
```
Package:   webware/mezzio-async
Namespace: Mezzio\Async
Config key: mezzio-async
CLI prefix: mezzio:async:*
```

### PHP Style
- `declare(strict_types=1)` in every file
- Constructor property promotion for readonly services
- `final` classes wherever inheritance is not explicitly designed for
- Typed properties throughout; no `mixed` unless required by interface contract

### Dependency Injection
- Every service has a corresponding `*Factory` class
- Factories receive `Psr\Container\ContainerInterface $container` as sole argument
- No service locator anti-pattern — inject all dependencies via constructor
- Register everything in `ConfigProvider::getDependencies()`
- `ConfigProvider::__invoke()` returns CLI dependencies only when `PHP_SAPI === 'cli'`

### Config Structure
```php
// mezzio-async.local.php
return [
    'mezzio-async' => [
        'http-server' => [
            'host' => '0.0.0.0',
            'port' => 80,
        ],
        'log_dir' => 'data/psr/log',   // optional; default is data/psr/log
    ],
];
```
`ServerFactory` reads `mezzio-async.http-server` first, then falls back to
the flat `mezzio-async` array. Default host `0.0.0.0`, port `8080`.

### Testing
- PHPUnit 11 (`phpunit.xml.dist`)
- Test namespace: `MezzioTest\Async\`
- Test directory: `test/MezzioTest/Async/`
- All new classes must have unit tests
- Integration tests requiring the extension must be skipped:
  `$this->markTestSkipped('true_async extension not available')`

---

## Critical Patterns

### Entering the Scheduler
The CLI entry point is synchronous PHP. Always wrap the entire server in `await(spawn(...))`:
```php
await(spawn(function () use ($server): void {
    // everything here runs inside TrueAsync
}));
```

### Accept Loop
```php
$scope->spawn(function () use ($server, $scope): void {
    try {
        while (true) {
            $peerName = '';
            $conn = @stream_socket_accept($server, -1, $peerName);
            if ($conn === false) {
                break; // scope cancelled
            }
            $scope->spawn($this->handleConnection(...), $conn, $peerName);
        }
    } finally {
        fclose($server);
    }
});
```
- `stream_socket_accept` takes **positional arguments only** — no named args
- `@` suppresses the warning emitted when accept is interrupted by cancellation
- The server socket is closed in `finally` of the accept coroutine

### Connection Handling — Parse Outside Logging try/finally
Browser speculative pre-connects open TCP without sending data; `parse()` returns `null`.
Parse must happen **outside** the logging `try/finally` so null connections are silently
dropped with no log entry:

```php
private function handleConnection(mixed $conn, string $peerName): void
{
    // Parse OUTSIDE logging try/finally — null = speculative pre-connect, no log
    try {
        $request = $this->parser->parse($conn, $peerName);
    } catch (Throwable $e) {
        $this->logger->warning('Parse error from ' . $peerName, ['exception' => $e]);
        fclose($conn);
        return;
    }

    if ($request === null) {
        fclose($conn);
        return;
    }

    // Real request from here — always log it
    $method  = $request->getMethod();
    $target  = $request->getRequestTarget();
    $startNs = hrtime(true);
    $status  = 500;

    try {
        // dispatch ...
    } catch (Throwable $e) {
        // error handling ...
    } finally {
        if (is_resource($conn)) fclose($conn);
        $ms = round((hrtime(true) - $startNs) / 1_000_000, 2);
        $this->logger->info(sprintf('%s %s %d %sms %s', $method, $target, $status, $ms, $peerName));
    }
}
```

### Shutdown
```php
await_any_or_fail([signal(Signal::SIGTERM), signal(Signal::SIGINT)]);
$scope->cancel();
$scope->awaitAfterCancellation(errorHandler: fn($e) => $logger->error(...));
```

---

## TrueAsync Usage Rules

1. **Never share I/O resources across concurrent coroutines.** Use `Async\Pool` for DB/Redis.
2. **All I/O is automatically non-blocking inside coroutines.** Do not use `stream_set_blocking`.
3. **`finally` blocks always run**, even on cancellation — close sockets there.
4. **`protect(callable)`** — use for critical sections (DB transactions).
5. **`Async\Scope`** — always scope coroutines. Avoid `spawn()` without an owning scope.
6. **Services are shared across all requests.** Keep them stateless.
7. **`Async\Context`** — use for request-scoped data (request ID, auth user).

---

## What to Avoid

- Do **not** add Swoole-specific classes or type hints (`Swoole\*`)
- Do **not** use FrankenPHP APIs
- Do **not** use `async`/`await` keywords (this is not JavaScript)
- Do **not** use ReactPHP, Amp, or other userland async libraries
- Do **not** create multi-process architectures — there is no master/worker split
- Do **not** call `pcntl_fork()` — not compatible with TrueAsync
- Do **not** use named arguments for `stream_socket_accept` — pass positionally

---

## Docker / Runtime

```dockerfile
FROM trueasync/php-true-async:latest
WORKDIR /var/www/app
CMD ["php", "bin/mezzio-async", "start"]
```

`docker-compose.yml` mounts `.:/var/www/app` and maps port `80:80`.  
Logs appear in `docker compose logs -f php` (via `php://stderr` handler).  
Local log file: `data/psr/log/async.log`.

---

## Build and Test

```bash
composer install
./vendor/bin/phpunit
./vendor/bin/phpstan analyse
./vendor/bin/php-cs-fixer fix --dry-run
```

## Key References

- `docs/planning/php-async-api.md` — TrueAsync API quick reference
- https://true-async.github.io/en/docs.html — canonical TrueAsync documentation
- https://true-async.github.io/en/docs.html — canonical TrueAsync documentation
- Attached `mezzio-swoole/` workspace — the reference implementation to mirror
