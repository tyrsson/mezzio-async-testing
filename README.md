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
    RequestParser.php          # raw bytes → PSR-7 ServerRequest
    ResponseEmitter.php        # PSR-7 Response → socket
    ServerRequestFactory.php   # assembles Laminas\Diactoros\ServerRequest
    StaticFileHandler.php      # serves public/ assets directly
  Log/
    LoggerDelegator.php        # adds file + stderr handlers to Monolog
  Runner/
    AsyncRunner.php            # core server: scope, accept loop, signal handling
    AsyncRunnerFactory.php
src/App/                  # application handlers, templates, routes
```

---

## Architecture Notes

- **One process, one thread, many coroutines.** `Async\Scope` owns all connection coroutines.
- **Services are long-lived.** All DI services are shared across every request — they must be stateless.
- **No `async`/`await` keywords.** TrueAsync uses standard PHP; I/O yields automatically inside coroutines.
- Routes are registered automatically via `RouteCollectorDelegator` — no `routes.php` callback needed.

