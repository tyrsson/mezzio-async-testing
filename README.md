# mezzio-async

A proof-of-concept integration of the [Mezzio](https://docs.mezzio.dev/) framework with
[PHP TrueAsync](https://true-async.github.io/en/docs.html) (`true_async` extension, PHP 8.6+).

The application runs as a **long-lived CLI process** — a single-threaded, single-process
coroutine server. There are no worker processes, no Swoole, no FrankenPHP, no ReactPHP.

---

## Current State

The core server is working end-to-end:

- TCP socket bound via `stream_socket_server`
- Accept loop running inside an `Async\Scope`; each connection is handled in its own coroutine
- HTTP/1.1 request parsing (`fread` loop, header + body)
- Full Mezzio middleware pipeline per request (routing, dispatch, error handling)
- PSR-7 response serialised back to the socket via `fwrite`
- Static files under `public/` served directly (path traversal protected), bypassing the pipeline
- Graceful shutdown on `SIGTERM` / `SIGINT` — in-flight connections drain before exit
- Request timing logged (pipeline + emit time, excluding read time)
- PSR-3 logger writing to `data/psr/log/async.log` and container stderr

Confirmed working: home page render, `/ping`, 404, CSS/static assets.

---

## Requirements

- PHP 8.6+ with the `true_async` extension (provided by the `trueasync/php-true-async` Docker image)
- Composer
- Docker + Docker Compose

---

## Running

```bash
docker compose up -d --build
```

The server listens on **http://localhost/** (port 80).

To tail logs in real time use the Docker extension **View Logs** on the container, or:

```bash
docker compose logs -f php
```

To stop:

```bash
docker compose down
```

---

## Project Structure

```
bin/mezzio-async          # CLI entry point — boots container, runs server
config/                   # Mezzio configuration
src/mezzio-async/src/     # async server package
  ConfigProvider.php
  Http/
    Server.php                 # TCP socket, scheduler entry, Scope, accept loop, signals
    ServerFactory.php
    RequestParser.php          # raw bytes → PSR-7 ServerRequest
    ResponseEmitter.php        # PSR-7 Response → socket
    ServerRequestFactory.php   # assembles Laminas\Diactoros\ServerRequest
    StaticFileHandler.php      # serves public/ assets directly
  Log/
    LoggerDelegator.php        # adds file + stderr handlers to Monolog
  Runner/
    AsyncRunner.php            # Mezzio integration: parse, dispatch, emit, log
    AsyncRunnerFactory.php
src/App/                  # application handlers, templates, routes
```

---

## Architecture Notes

- **One process, one thread, many coroutines.** `Async\Scope` owns all connection coroutines.
- **`Http\Server`** owns the socket, TrueAsync scheduler entry, `Scope` lifecycle, accept loop, and signal handling.
- **`Runner\AsyncRunner`** is a thin Mezzio integration layer — it delegates server lifecycle to `Http\Server` and handles only individual connections (parse, dispatch, emit, log).
- **Services are long-lived.** All DI services are shared across every request — they must be stateless.
- **No `async`/`await` keywords.** TrueAsync uses standard PHP; I/O yields automatically inside coroutines.
- Routes are registered automatically via `RouteCollectorDelegator` — no `routes.php` callback needed.

---

## Debugging (WSL / Dev Container)

TrueAsync ships its own custom Xdebug extension (`xdebug.so`). On Linux the extension filename
differs from the Windows build (`php_xdebug.dll`). The `.devcontainer/docker/php/Dockerfile`
creates a symlink to bridge the gap:

```dockerfile
RUN ln -s /usr/local/lib/php/extensions/no-debug-zts-20250926/xdebug.so \
          /usr/local/lib/php/extensions/no-debug-zts-20250926/php_xdebug.so
```

This allows `zend_extension = php_xdebug` in `docker/php/conf.d/xdebug.ini` to work on both
platforms without change.

### Xdebug mode

`xdebug.start_with_request = yes` is required — TrueAsync's custom Xdebug fires a debug session
per coroutine (per HTTP request), but only in `yes` mode.

### Startup workflow

Because `start_with_request = yes` triggers a debug session on every PHP invocation, the server
startup itself will pause waiting for the debug client. Always follow this order:

1. **Start "Listen for Xdebug"** in VS Code (Run & Debug panel, or F5)
2. **Start the server** in a terminal:
   ```bash
   php bin/mezzio-async start
   ```
3. The server is now running; all HTTP requests will hit breakpoints normally

> If the debug listener is not active before starting the server, the server will hang
> indefinitely waiting for a connection on port 9003.

