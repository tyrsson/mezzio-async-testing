# Ramp Test (Capped) — Results

**Date:** 2026-04-20  
**Script:** `test/k6/ramp.js`  
**Mode:** `?mode=ramp` (concurrent coroutines, JSON response)  
**Outcome:** All checks passed — no crash, no errors

Raw console output: `test/k6/results/ramp-capped-2026-04-20.txt`

---

## Test Configuration

| Parameter | Value |
|---|---|
| k6 version | 0.56.0 |
| Executor | `ramping-vus` |
| Peak VUs | 10 (hard cap = DB pool max) |
| Total duration | 4m 30s |
| Endpoint | `GET /postgres?mode=ramp` |
| Queries per request | 4 concurrent coroutines |
| DB pool | min=2, max=10 |

### Stages

| Stage | Duration | Target VUs | Concurrent pool slots |
|---|---|---|---|
| Ramp | 20s | 1 → 2 | up to 8 |
| Hold | 30s | 2 | up to 8 |
| Ramp | 20s | 2 → 3 | up to 12 — **saturation begins** |
| Hold | 30s | 3 | up to 12 |
| Ramp | 20s | 3 → 5 | up to 20 |
| Hold | 30s | 5 | up to 20 |
| Ramp | 20s | 5 → 8 | up to 32 |
| Hold | 30s | 8 | up to 32 |
| Ramp | 20s | 8 → 10 | up to 40 |
| Hold | 30s | 10 | up to 40 |
| Cool-down | 20s | 10 → 0 | — |

---

## Aggregate Results (full run)

| Metric | Value |
|---|---|
| Total requests | 537,679 |
| Error rate | **0.00%** |
| Checks passed | 1,075,358 / 1,075,358 (100%) |
| Overall throughput | ~2,000 req/s |
| `http_req_duration` avg | 2.33ms |
| `http_req_duration` p90 | 3.99ms |
| `http_req_duration` **p95** | **4.25ms** |
| `http_req_duration` max | 27.73ms |
| `iteration_duration` avg | 2.54ms |
| `iteration_duration` p95 | 4.45ms |

---

## Per-Stage Throughput

Calculated from iteration counts at stage boundaries in the progress output.

| Stage | VUs | Approx. req/s | Notes |
|---|---|---|---|
| Hold at 2 VUs | 2 | ~1,609 | 8 pool slots — no queuing |
| Hold at 3 VUs | 3 | ~2,006 | 12 slots — pool queuing begins |
| Hold at 5 VUs | 5 | ~2,217 | 20 slots — queuing well established |
| Hold at 8 VUs | 8 | ~2,336 | 32 slots |
| Hold at 10 VUs | 10 | ~2,288 | 40 slots — **peak ceiling** |

---

## Analysis

### Throughput plateau confirms pool saturation at 3 VUs

Throughput more than doubled from 1 VU (~800 req/s) to 3 VUs (~2,000 req/s), then
effectively plateaued. Adding VUs beyond 3 produced only marginal gains (~15% from
3→10 VUs) because the DB pool (max=10) became the bottleneck — excess coroutines
queue on pool acquire rather than executing in parallel.

This matches the theoretical prediction: `3 VUs × 4 slots = 12 > pool max of 10`.

### p95 of 4.25ms across the entire run

This is the aggregate p95 across all VU levels including the 10-VU peak. Since the
majority of requests ran at lower VU counts (more time spent in earlier stages), the
true p95 at sustained 10 VUs is likely somewhat higher. The max of 27.73ms represents
the tail during pool-acquire queuing spikes at peak load.

### No crash — extension stable at 10 VUs

The previous test (uncapped, to 20 VUs) crashed with SIGABRT at t=139s under ~80
simultaneous coroutines. This run peaked at ~41 coroutines (10 VUs × 4 + 1 accept)
and completed cleanly. The crash boundary lies somewhere between 41 and 81 coroutines.

### Max useful throughput

**~2,300 req/s** is the practical ceiling with the current pool size of 10.
Each request hits the DB 4 times, so this represents approximately **9,200 DB
queries/second** sustained, which is significant for a single-process PHP server.

---

## Comparison: Before and After Capping

| Run | Peak VUs | Outcome | Peak req/s | p95 |
|---|---|---|---|---|
| `ramp.js` (original, 20 VU) | 20 | **Crash** at t=139s (SIGABRT) | N/A | N/A |
| `ramp.js` (capped, 10 VU) | 10 | **Clean** — 0% error rate | ~2,300 | 4.25ms |

---

## Recommendations

1. **Keep the 10 VU cap** — there is no throughput benefit above pool max, only
   coroutine accumulation risk.

2. **Increase pool max** to test higher throughput ceilings — e.g., `max=20` would
   allow 5 VUs to run without queuing, potentially pushing past 3,000 req/s before
   the next plateau.

3. **Run `soak.js` (10 VUs, 10m)** to validate that the 2,300 req/s ceiling is
   stable over time and no memory growth or connection leaks occur.

4. **Investigate the crash boundary** — a targeted test stepping from 10→15 VUs
   slowly would pinpoint exactly where the extension becomes unstable, which is
   useful data for a TrueAsync bug report.
