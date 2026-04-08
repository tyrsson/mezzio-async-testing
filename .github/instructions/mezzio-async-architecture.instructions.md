---
description: "Use when creating, modifying, or reviewing any mezzio-async source files: ConfigProvider, AsyncRequestHandlerRunner, event classes, listeners, commands, factories, or any class in the Mezzio\\Async namespace. Covers DI conventions, event system design, command structure, and service statefulness rules."
applyTo: "src/**/*.php"
---

# mezzio-async Architecture Conventions

## Namespace and Package

```
Root namespace : Mezzio\Async
Package        : webware/mezzio-async
Config key     : mezzio-async
CLI prefix     : mezzio:async:*
Test namespace : MezzioTest\Async\
```

---

## ConfigProvider

`Mezzio\Async\ConfigProvider` is the single entry point registered in
`config/config.php`. It must:

- Return dependencies from `getDependencies()` only when `PHP_SAPI === 'cli'`
- Return default config under `mezzio-async` key from `getDefaultConfig()`
- Return console commands under `laminas-cli` key from `getConsoleConfig()`
- Register `AsyncRequestHandlerRunner` as the `RequestHandlerRunnerInterface` alias

```php
public function __invoke(): array
{
    $config = PHP_SAPI === 'cli'
        ? ['dependencies' => $this->getDependencies()]
        : [];

    $config['mezzio-async'] = $this->getDefaultConfig();
    $config['laminas-cli']  = $this->getConsoleConfig();

    return $config;
}
```

---

## Factory Pattern

Every service class has a corresponding `*Factory` implementing `__invoke(ContainerInterface $c)`:

```php
final class MyServiceFactory
{
    public function __invoke(ContainerInterface $container): MyService
    {
        return new MyService(
            $container->get(DependencyA::class),
            $container->get(DependencyB::class),
        );
    }
}
```

- Factories are `final` (unless explicitly designed for extension)
- Factories use constructor property promotion when they hold no state
- Registration in `ConfigProvider::getDependencies()`:

```php
'factories' => [
    MyService::class => MyServiceFactory::class,
],
```

---

## Event System

All lifecycle events are in `Mezzio\Async\Event`. They are plain value objects dispatched
through the PSR-14 `EventDispatcherInterface` injected into `AsyncRequestHandlerRunner`.

### Event Naming Convention

| Event Class              | When Dispatched                                      |
|--------------------------|------------------------------------------------------|
| `ServerStartEvent`       | After socket bound, before first `accept()` call     |
| `ServerShutdownEvent`    | After accept loop exits and all connections drained  |
| `RequestEvent`           | After HTTP parse succeeds, before pipeline dispatch  |
| `RequestHandledEvent`    | After `$handler->handle()` returns a response        |
| `ConnectionErrorEvent`   | On socket read/write failure for a connection        |
| `BeforeReloadEvent`      | On SIGUSR1, before draining in-flight connections    |
| `AfterReloadEvent`       | After reload completes                               |

### Event Class Shape

```php
final class RequestEvent
{
    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly mixed $connectionResource,
    ) {}

    public function getRequest(): ServerRequestInterface { return $this->request; }
    public function getConnectionResource(): mixed { return $this->connectionResource; }
}
```

Events are immutable value objects. Do **not** add setters or mutable state.

### Listener Registration

Listeners are registered in config, not hardwired in `AsyncRequestHandlerRunner`:

```php
// config/autoload/mezzio-async.global.php
return [
    'mezzio-async' => [
        'listeners' => [
            RequestEvent::class => [
                RequestHandlerRequestListener::class,
            ],
        ],
    ],
];
```

---

## AsyncRequestHandlerRunner

- Implements `Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface`
- Is `final`
- Injects `EventDispatcherInterface`, config array, and server dependencies — no container
- Does **not** contain business logic; dispatches events and delegates to listeners
- The main `run()` method creates the `Async\Scope`, binds the server socket, spawns the
  accept loop, spawns the signal handler, then calls `$scope->awaitCompletion()`

---

## TaskGroup and TaskSet — Which to Use Where

| Primitive   | Use in mezzio-async for                                                  |
|-------------|--------------------------------------------------------------------------|
| `TaskSet`   | Connection tracking in accept loop; `BackgroundTaskDispatcher` internals |
| `TaskGroup` | Request-level parallel fan-out in handlers; startup initialisation batch |

**`TaskSet` for the accept loop** — connections arrive indefinitely; completed ones
auto-remove from the set; `seal()` + `awaitCompletion()` is the graceful drain gate on
shutdown; `count()` exposes live active-connection metrics to `StatusCommand`.

**`TaskGroup` for request fan-out** — inside a Mezzio handler, use `TaskGroup` to run
multiple I/O calls concurrently and aggregate results with `await($group->all())`. Document
and promote this pattern in examples — it replaces Swoole task workers at the application
layer entirely.

**Never** use a bare `Async\Scope` for connection tracking or background task
dispatching when `TaskSet` is available — it lacks the `count()` observability and the
seal/drain semantics needed for graceful shutdown.

---

## No Worker/Manager Events

mezzio-async is single-process. There are no equivalents for Swoole's:
- `ManagerStartEvent` / `ManagerStopEvent`
- `WorkerStartEvent` / `WorkerStopEvent` / `WorkerErrorEvent`
- `TaskEvent` / `TaskFinishEvent`

Do not create these. Background work is just `spawn()` within the server scope.

---

## Command Layer

Each CLI command is a separate class in `Mezzio\Async\Command\`:

| Class            | Laminas CLI name          | Action                                       |
|------------------|---------------------------|----------------------------------------------|
| `StartCommand`   | `mezzio:async:start`      | Start server, write PID, block               |
| `StopCommand`    | `mezzio:async:stop`       | Read PID, send SIGTERM                       |
| `ReloadCommand`  | `mezzio:async:reload`     | Read PID, send SIGUSR1                       |
| `StatusCommand`  | `mezzio:async:status`     | Read PID, check process exists               |

All commands use `PidManager` to locate the running process. Commands must gracefully handle
the case where the PID file does not exist.

---

## PidManager

`Mezzio\Async\PidManager` holds the path to the PID file and provides `read()` / `write()` /
`delete()` methods. Default file: `data/mezzio-async.pid`. The path is configurable.

---

## Service Statefulness

TrueAsync is single-process. DI services live for the server lifetime and are shared across
all request coroutines. Rules:

1. **Services must be stateless by design** — no per-request caches or accumulated data
2. **Never use static variables** for per-request state — use `Async\Context` instead
3. **Template renderers** — do not call `addDefaultParam()` with request-derived data
4. **Authentication context** — inject a request-scoped accessor backed by `Async\Context`,
   not a stateful service
5. **Anything that wraps a single I/O connection** (PDO, Redis) must go through `Async\Pool`

---

## Static Resource Handler

`Mezzio\Async\StaticResourceHandler` mirrors `Mezzio\Swoole\StaticResourceHandler`. It
receives a `RequestEvent`, checks whether the request targets a static asset in the public
directory, and writes the response directly to the socket without invoking the Mezzio
middleware pipeline. Because `fread()` inside a coroutine automatically yields, no extra
async wiring is needed.

---

## Hot Code Reload

`Mezzio\Async\HotCodeReload\FileWatcher` wraps `Async\FileSystemWatcher`. On change detection:

1. Dispatch `BeforeReloadEvent`
2. Stop accepting new connections (close server socket)
3. Drain in-flight coroutines via `$connectionScope->awaitCompletion(timeout($drainMs))`
4. `opcache_reset()` if OPcache is active
5. Re-require or rely on the long-lived process having the new file loaded
6. Re-create server socket and restart accept loop
7. Dispatch `AfterReloadEvent`

> Hot reload is only meaningful for configuration/listener changes. PHP classes are loaded
> once and cannot be truly re-required without a process restart. Advise users to use
> `--hot-reload` only in development.
