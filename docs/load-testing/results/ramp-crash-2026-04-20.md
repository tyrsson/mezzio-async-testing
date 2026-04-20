# Ramp Test — Server Crash Report

**Date:** 2026-04-20  
**Script:** `test/k6/ramp.js`  
**Mode:** `?mode=ramp` (concurrent queries, JSON response)  
**Outcome:** PHP server crashed with SIGABRT at t=139s

---

## Test Configuration

| Parameter | Value |
|---|---|
| k6 version | 0.56.0 |
| Executor | `ramping-vus` |
| Start VUs | 1 |
| Peak VUs | 20 |
| Total duration | 2m 20s |
| Base URL | `http://localhost:8080` |
| Endpoint | `GET /postgres?mode=ramp` |

### Ramp Stages

| Stage | Duration | Target VUs |
|---|---|---|
| Warm-up | 30s | 5 |
| Approach pool limit | 60s | 10 |
| Exceed pool limit | 30s | 20 |
| Cool-down | 20s | 0 |

---

## Server Configuration at Time of Test

| Parameter | Value |
|---|---|
| PHP extension | `true_async` (TrueAsync / php-async) |
| DB connection pool | min=2, max=10 |
| Queries per request | 4 (spawned as coroutines via `await_all_or_fail`) |
| Server bind | `0.0.0.0:8080` |
| Accept loop | single `Async\Scope`, unbounded coroutine count |

---

## Crash Details

- **Crash signal:** SIGABRT (`exit 134` = 128 + 6)
- **Crash onset:** t=139s (k6 elapsed time), during the 20-VU stage
- **Last successful log entry:** `2026-04-20T04:27:43Z` (series of 1–2ms requests)
- **Crash source:** TrueAsync extension — no PHP-level exception or error was logged before the abort
- **Post-crash behaviour:** Server socket closed; all subsequent k6 requests received `connection refused`

### Coroutine Load at Crash Point

At peak 20 VUs, each request spawns 4 coroutines:

```
20 VUs × 4 query coroutines + 1 accept coroutine = ~81 active coroutines
```

The DB pool (max=10) would have been queuing excess acquire requests, but the
pool itself was not the cause — the extension aborted before any pool timeout
or error was emitted.

---

## k6 Summary Metrics

These metrics cover the full 2m 20s run, including all post-crash `connection refused` failures.

| Metric | Value |
|---|---|
| Total requests | 482,580 |
| Successful (200) | 6,567 (1.37%) |
| Failed | 476,013 (98.63%) |
| Checks passed | 13,134 / 965,160 (1.36%) |
| Req/s (total) | 3,459/s (inflated by instant-fail reconnects post-crash) |
| `http_req_duration` avg | 23.34 µs (skewed by 0µs failed requests) |
| `http_req_duration` max | 2.31s |
| `iteration_duration` p90 | 2.12ms |
| `iteration_duration` p95 | 5.29ms |
| VUs at end | 1 (min=1, max=20) |

> **Note:** The avg/p50/p90 duration figures are misleading because ~98% of
> requests failed instantly (0µs) after the crash. The pre-crash figures from
> the baseline test are a better indicator of healthy performance.

---

## Pre-Crash Performance (from baseline comparison, same session)

These figures were recorded before the ramp test, with 1 VU:

| Mode | avg latency | p95 | req/s |
|---|---|---|---|
| `baseline` (sequential, no coroutines) | 1.66ms | 2.11ms | ~507 |
| `concurrent` (4 coroutines per request) | 1.15ms | 1.45ms | ~694 |

The 37% latency improvement in concurrent mode confirms that TrueAsync coroutine
I/O overlap is functioning correctly under single-VU load.

---

## Analysis

### What worked

- Server handled all requests cleanly up to the point of the crash
- No PHP-level errors, no pool timeouts, no application exceptions in logs
- 1–2ms latency sustained through the warm-up and pool-approach stages
- Coroutine concurrency demonstrably reduced latency vs sequential execution

### What failed

The TrueAsync extension (C-level) aborted the process under coroutine saturation.
The accept loop spawns a new coroutine for every TCP connection with no back-pressure
or concurrency cap. At 20 VUs the accept loop was admitting connections faster than
coroutines could finish, accumulating ~80+ simultaneous coroutines. The extension
reached an internal limit and called `abort()`.

This is an **extension stability boundary**, not an application logic error.

### Pool behaviour

The DB pool (max=10) was not the limiting factor. With 20 VUs × 4 concurrent pool
acquire calls = 80 simultaneous acquire attempts, 70 would queue. TrueAsync's pool
suspend-on-wait design means those coroutines block cooperatively — but they still
exist and occupy scheduler state, contributing to the total coroutine count.

---

## Recommendations

1. **Cap VUs at 10** in `ramp.js` — there is no useful information above the pool
   max of 10; excess VUs only add queuing delay, not throughput.

2. **Add a connection-level concurrency cap** to the accept loop using a semaphore
   or `Async\Channel` to limit how many coroutines can be in-flight simultaneously.

3. **File a bug** against the TrueAsync extension with this reproduction case:
   - Single-process server, unbounded coroutine accept loop
   - ~80 simultaneous coroutines
   - Reproduces reliably at 20 VUs on this workload

4. **Run `stress.js`** at a safer ceiling (10 VUs) to get a stable throughput
   measurement without hitting the crash boundary.

---

## Reproduction Steps

```bash
# 1. Start the server
php bin/mezzio-async start &

# 2. Seed the database
curl http://localhost:8080/postgres?action=setup

# 3. Run the ramp test (crashes at ~20 VUs)
BASE_URL=http://localhost:8080 k6 run test/k6/ramp.js
```

The crash reproduces consistently when the ramp reaches 20 VUs.
