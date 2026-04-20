# Load Testing: Server Setup Requirements

This document describes everything that must be in place — or changed — before running k6
load tests against the `mezzio-async` server. Read alongside [overview.md](./overview.md)
and [k6-scripts.md](./k6-scripts.md).

---

## 1. Install k6

k6 is not currently in the dev container image. It must be installed before any test run.

### Option A — Install in the running container (ephemeral)
```bash
docker compose exec php bash -c "
  apt-get update -q &&
  apt-get install -y gnupg &&
  gpg --no-default-keyring \
      --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
      --keyserver hkp://keyserver.ubuntu.com:80 \
      --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69 &&
  echo 'deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main' \
      > /etc/apt/sources.list.d/k6.list &&
  apt-get update -q &&
  apt-get install -y k6
"
```

### Option B — Install in the Dockerfile (persistent, recommended)
Add to `docker/php/Dockerfile`:
```dockerfile
RUN apt-get update -q \
    && apt-get install -y gnupg \
    && gpg --no-default-keyring \
           --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
           --keyserver hkp://keyserver.ubuntu.com:80 \
           --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69 \
    && echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
           > /etc/apt/sources.list.d/k6.list \
    && apt-get update -q \
    && apt-get install -y k6 \
    && rm -rf /var/lib/apt/lists/*
```

### Option C — Run k6 from the host
If k6 is installed on the host machine (macOS: `brew install k6`), it can hit the
server at `http://localhost:8080` directly since port 8080 is already published.

---

## 2. Disable Xdebug During Load Tests

`docker/php/conf.d/xdebug.ini` sets `xdebug.start_with_request = yes`.
Xdebug adds significant per-request overhead and will severely skew latency results.

This branch includes `docker/php/conf.d/xdebug-loadtest.ini` (`xdebug.mode = off`) and
`docker-compose.loadtest.yml` which mounts it in place of the dev xdebug config.
Use the loadtest compose file (see section 10) — no manual edits needed.

---

## 3. Turn Off `display_errors` During Load Tests

`docker/php/conf.d/error_reporting.ini` sets `display_errors = On`. Under load this means
any PHP warning/error is written into the HTTP response body, potentially corrupting the
HTML and causing k6 `checkHtml` checks to fail even when the logic is sound.

This branch includes `docker/php/conf.d/error_reporting.loadtest.ini` (`display_errors = Off`,
`log_errors = On`) mounted by `docker-compose.loadtest.yml`. Errors will still appear in
the async log (`data/psr/log/async.log`) via the Monolog handler.

---

## 4. Clear the Config Cache

Mezzio caches its config at `data/cache/config-cache.php`. If pool configuration is changed
(e.g. bumping `max` for a test run), the cache must be cleared first or the old values will
be used.

```bash
# From workspace root
php bin/clear-config-cache.php

# Or delete directly
rm -f data/cache/config-cache.php
```

The server must then be restarted for the new config to take effect.

---

## 5. Ensure the Server is Running in Foreground / Reachable

The TrueAsync server is a long-lived CLI process. Verify it is running:
```bash
docker compose ps          # php container should be Up
curl -s http://localhost:8080/ping   # should return {"ack":true} or similar
```

If not running:
```bash
docker compose up -d php
```

If the server crashed, check:
```bash
docker compose logs php
tail -30 data/psr/log/async.log
```

---

## 6. Pool Sizing Considerations

The current pool config in `src/App/src/ConfigProvider.php`:
```php
'pool' => [
    'min' => 2,
    'max' => 10,
    'healthcheck_interval_ms' => 30_000,
],
```

Each `/postgres` request acquires up to **4 pool slots simultaneously** (one per concurrent
coroutine). Effective saturation begins around **3 concurrent VUs** (3 × 4 = 12 > max 10).

### For intentional saturation tests (stress.js)
Keep `max=10` — the saturation is the point of the test.

### For throughput ceiling tests
Temporarily increase `max` to remove pool contention as a variable:
```php
'max' => 50,
```
This isolates PostgreSQL query latency from pool-acquire queuing latency, revealing the
server's raw I/O concurrency capacity.

Remember to clear the config cache and restart the server after changing the pool size.

---

## 7. PostgreSQL Connection Limit

The `postgres` container uses `FROM postgres:latest` with default `max_connections = 100`.
With pool `max=10` this is not a concern for a single server instance. It only becomes
relevant if you run multiple server instances or increase `max` significantly above 50.

To check current PostgreSQL connection limit:
```bash
docker compose exec postgres psql -U postgres -c "SHOW max_connections;"
```

To increase it (if ever needed), add a `docker/database/postgres/postgresql.conf` and mount
it in `docker-compose.yml`:
```
max_connections = 200
```

---

## 8. Seed Data Must Be in Place

All load test scenarios (except `ping-baseline.js`) query the four benchmark tables
(`bm_users`, `bm_products`, `bm_orders`, `bm_order_items`). These must be created and seeded
before the test starts.

The `setup()` hook in `baseline.js`, `ramp.js`, and `soak.js` handles this automatically.
`stress.js` and `ping-baseline.js` skip the setup hook to avoid inflating early-iteration
latency metrics — run baseline or ramp first, or seed manually:

```bash
curl -s "http://localhost:8080/postgres?action=setup"
```

To reset between test runs:
```bash
curl -s "http://localhost:8080/postgres?action=teardown"
curl -s "http://localhost:8080/postgres?action=setup"
```

---

## 9. Create the Results Directory

The `test/k6/results/` directory needs to exist before writing JSON output:
```bash
mkdir -p test/k6/results
```
A `.gitignore` is already in place to exclude `*.json` and `*.csv` from the repository.

---

## 10. `docker-compose.loadtest.yml`

This file is committed in this branch. It applies all load-test-friendly settings:
- Mounts `xdebug-loadtest.ini` (xdebug.mode=off) over the dev xdebug config
- Mounts `error_reporting.loadtest.ini` (display_errors=Off) over the dev error config

```bash
# Start in load-test mode
docker compose -f docker-compose.yml -f docker-compose.loadtest.yml up -d --build

# Stop
docker compose -f docker-compose.yml -f docker-compose.loadtest.yml down

# Restart server only (after config cache clear)
docker compose -f docker-compose.yml -f docker-compose.loadtest.yml restart php
```

---

## Summary Checklist

Before each load test run:

- [ ] k6 is installed (container or host)
- [ ] Xdebug `start_with_request` is disabled
- [ ] `display_errors` is Off
- [ ] Config cache cleared (`php bin/clear-config-cache.php`) if pool config was changed
- [ ] Server is running and responding to `GET /ping`
- [ ] Seed data is in place (`GET /postgres?action=setup`)
- [ ] `test/k6/results/` directory exists
- [ ] Pool `max` is set to the value appropriate for the scenario being run
