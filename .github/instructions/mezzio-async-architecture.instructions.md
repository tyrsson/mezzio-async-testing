---
description: "Use when creating, modifying, or reviewing any mezzio-async source files: ConfigProvider, AsyncRunner, factories, or any class in the Mezzio\\Async namespace. Covers DI conventions, command structure, and service statefulness rules."
applyTo: "src/mezzio-async/src/**/*.php"
---

# mezzio-async Architecture Conventions

## Namespace and Package

```
Root namespace : Mezzio\Async
Package        : webware/mezzio-async
Config key     : mezzio-async
CLI prefix     : mezzio:async:*
Test namespace : MezzioTest\Async\
Test directory : test/MezzioTest/Async/
```

---

## Actual Source Tree

```
src/mezzio-async/src/
  ConfigProvider.php                 ← single DI entry point
  Runner/
    AsyncRunner.php                  ← implements RequestHandlerRunnerInterface; connection handling
    AsyncRunnerFactory.php
  Http/
    Server.php                       ← TCP socket, scheduler entry, Scope, accept loop, signals
    ServerFactory.php
    RequestParser.php                ← fread accumulator → ?ServerRequestInterface
    RequestParserFactory.php
    ResponseEmitter.php              ← fwrite HTTP/1.1 serialiser
    ResponseEmitterFactory.php
    ServerRequestFactory.php         ← invokable PSR-7 builder
    ServerRequestFactoryFactory.php
    StaticFileHandler.php            ← public/ asset server
    StaticFileHandlerFactory.php
  Log/
    LoggerDelegator.php              ← adds StreamHandlers to Monolog logger
```

**Not yet implemented — do not fabricate these:**
- `Command/` (Start, Stop, Reload, Status)
- `Event/` (PSR-14 lifecycle events and listeners)
- `PidManager`
- `HotCodeReload/`

---

## ConfigProvider

`Mezzio\Async\ConfigProvider` is the single DI entry point registered in `config/config.php`.

```php
public function __invoke(): array
{
    $config = PHP_SAPI === 'cli'
        ? ['dependencies' => $this->getDependencies()]
        : [];

    $config['mezzio-async'] = $this->getDefaultConfig();

    return $config;
}
```

Rules:
- Return dependencies only when `PHP_SAPI === 'cli'`
- Default config lives under the `mezzio-async` key
- `RequestHandlerRunnerInterface::class` is aliased to `AsyncRunner::class`
- `LoggerInterface::class` has a delegator array: `[LoggerDelegator::class]`

Current `getDependencies()` registration:

```php
'delegators' => [
    LoggerInterface::class => [LoggerDelegator::class],
],
'factories' => [
    AsyncRunner::class          => AsyncRunnerFactory::class,
    RequestParser::class        => RequestParserFactory::class,
    ResponseEmitter::class      => ResponseEmitterFactory::class,
    Server::class               => ServerFactory::class,
    ServerRequestFactory::class => ServerRequestFactoryFactory::class,
    StaticFileHandler::class    => StaticFileHandlerFactory::class,
],
'aliases' => [
    RequestHandlerRunnerInterface::class => AsyncRunner::class,
],
```

---

## Factory Pattern

Every service has a `final` `*Factory` class with a single `__invoke(ContainerInterface)`:

```php
final readonly class MyServiceFactory
{
    public function __invoke(ContainerInterface $container): MyService
    {
        return new MyService(
            $container->get(DependencyA::class),
        );
    }
}
```

- `final` and `readonly` unless explicitly designed for extension
- Single argument: `ContainerInterface $container`
- No service locator anti-pattern inside the service itself
- Register in `ConfigProvider::getDependencies()`

---

## Http\Server

`Mezzio\Async\Http\Server` is `final readonly`. It owns the entire server lifecycle.

Constructor:
```php
public function __construct(
    private string          $host,
    private int             $port,
    private LoggerInterface $logger,
)
```

`ServerFactory` reads config from `$config['mezzio-async']['http-server']`, falling
back to the flat `$config['mezzio-async']` array. Default host `0.0.0.0`, port `8080`.

The `listen(callable $connectionHandler): void` method:
1. Creates `stream_socket_server` with `tcp_nodelay` option
2. Wraps everything in `await(spawn(...))` to enter the TrueAsync scheduler
3. Creates a `Scope` with an exception handler that logs unhandled connection errors
4. Spawns the accept loop coroutine into the scope; passes each connection to `$connectionHandler`
5. Calls `await_any_or_fail([signal(Signal::SIGTERM), signal(Signal::SIGINT)])`
6. Calls `$scope->cancel()` then `$scope->awaitAfterCancellation(...)`

---

## AsyncRunner

`Mezzio\Async\Runner\AsyncRunner` implements `RequestHandlerRunnerInterface` and is `final readonly`.

Constructor receives:
```php
public function __construct(
    private RequestHandlerInterface $handler,   // Mezzio\ApplicationPipeline
    private RequestParser           $parser,
    private ResponseEmitter         $emitter,
    private StaticFileHandler       $staticFiles,
    private LoggerInterface         $logger,
    private Server                  $server,
)
```

`AsyncRunnerFactory` pulls all dependencies directly from the container — no config reading.
Config is read exclusively by `ServerFactory`.

The `run()` method is a single delegation:
```php
public function run(): void
{
    $this->server->listen($this->handleConnection(...));
}
```

All connection logic lives in `handleConnection(mixed $conn, string $peerName): void`.

---

## LoggerDelegator

`Mezzio\Async\Log\LoggerDelegator` is registered as a delegator on `LoggerInterface::class`.

It pushes two `Monolog\Handler\StreamHandler` instances with `useLocking: false`:
- `{log_dir}/async.log` — local file (default: `data/psr/log/async.log`)
- `php://stderr` — visible in `docker compose logs -f php`

The log directory is auto-created if it does not exist. The `log_dir` path is read from
`$config['mezzio-async']['log_dir']`.

**Why `useLocking: false`?** Locking buffers output until the lock is acquired. Disabling it
ensures log entries are written immediately, which is required for real-time Docker log tailing.

---

## Config Structure

```php
// config/autoload/mezzio-async.local.php
return [
    'mezzio-async' => [
        'http-server' => [
            'host' => '0.0.0.0',
            'port' => 80,
        ],
        'log_dir' => 'data/psr/log',  // optional
    ],
];
```

---

## Service Statefulness

Services live for the entire server lifetime, shared across all request coroutines.

1. **Services must be stateless** — no per-request caches or accumulated data
2. **Never use static variables** for per-request state — use `Async\Context` instead
3. **Anything wrapping a single I/O connection** (PDO, Redis) must go through `Async\Pool`

---

## What to Avoid

- Do **not** create `Event/`, `Command/`, `PidManager`, or `HotCodeReload/` until they are
  explicitly planned and designed
- Do **not** use PSR-14 event dispatching yet — the event system is not implemented
- Do **not** reference `mezzio-swoole` class names or patterns — this is a separate project
- Do **not** use named arguments for `stream_socket_accept` — positional only
