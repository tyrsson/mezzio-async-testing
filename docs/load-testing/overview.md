# Load Testing: Overview & Goals

## What We're Testing

The `mezzio-async` server running a **single-process, single-thread, many-coroutines** TrueAsync
architecture. The benchmark endpoint `GET /postgres` is the primary subject — it fires four
independent PostgreSQL SELECT queries as concurrent coroutines and awaits them all via
`await_all_or_fail()`. The pool is configured with `min=2, max=10` connections.

**Endpoints under test:**

| Route | Description |
|---|---|
| `GET /ping` | No-DB baseline; pure server overhead |
| `GET /postgres` | 4 concurrent DB queries, HTML response with profiler data |
| `GET /postgres?action=setup` | One-time DDL + seed (not load tested; prerequisite) |
| `GET /postgres?action=teardown` | Cleanup (not load tested) |

**Server:** `http://localhost:8080`  
**Pool:** min=2, max=10 connections to PostgreSQL

---

## Goals

1. **Establish baseline latency** for a single sequential request (no concurrency pressure).
2. **Characterise pool saturation** — observe what happens when VUs exceed the effective pool
   capacity. Since each request spawns 4 coroutines that each need their own pool slot,
   effective pool exhaustion occurs well below 10 concurrent VUs (3 VUs × 4 slots = 12 needed,
   max = 10).
3. **Demonstrate coroutine concurrency benefit** — the four queries overlap in time. The
   profiler records `elapsed_ms` per query and `totalMs` for the whole block. Under low
   concurrency, `totalMs` should be close to `max(individual_elapsed_ms)` rather than their
   sum, proving non-blocking overlap.
4. **Find throughput ceiling** — the RPS plateau before error rate climbs.
5. **Compare with synchronous baseline** (future): same queries executed sequentially without
   `spawn`/`await_all_or_fail` as a control to quantify the concurrency gain.

---

## Architecture Constraints Relevant to Load Testing

- **Single process, no forking.** All concurrency is cooperative coroutine scheduling on one OS
  thread. CPU-bound work will not parallelise — only I/O-bound work yields to the scheduler.
- **Connection pool `max=10`.** Each `fetchRows()` call acquires one pool slot for the duration
  of the query. Each `/postgres` request needs up to 4 simultaneous slots. With 3 concurrent
  VUs all hitting `/postgres` simultaneously, up to 12 slots could be demanded — above the
  pool max. Pool acquire will **suspend the coroutine** (not error) until a slot is released.
- **No worker processes.** There is no horizontal scaling within a single instance. All
  requests, including DB I/O waits, are handled by the same event loop.
- **`await_all_or_fail` is the key primitive.** The `totalMs` value rendered in the HTML page
  is the time from spawning all 4 coroutines to when the last one completes — this is the
  primary observable metric for concurrency efficiency.

---

## Test Scenarios

### 1. Baseline (1 VU, 30s)
Single virtual user, sequential requests. Establishes:
- Minimum achievable latency (p50, p95)
- Single-request RPS ceiling
- Confirms profiler data: `totalMs ≈ max(query elapsed_ms)` (not the sum)

### 2. Concurrency Ramp (1→20 VUs over ~2 minutes)
Gradually increases concurrent users to show:
- Latency behaviour as pool slots are contested
- The VU count at which pool saturation begins (watch p95 latency inflection)
- Error rate onset (pool acquire timeout, if any)

Stages: 1→5 VUs (30s) → 5→10 VUs (60s) → 10→20 VUs (30s) → 20→0 VUs (20s)

### 3. Pool Saturation Stress (25 VUs, 60s)
Sustained load well above effective pool capacity (25 VUs × 4 slots = 100 needed, max=10).
Demonstrates:
- Queuing behaviour: coroutines suspend for pool slots rather than failing immediately
- Whether the server remains stable or leaks/crashes under sustained pressure
- Throughput under saturation

### 4. Soak / Stability (10 VUs, 10 minutes)
Sustained moderate load to detect:
- Memory growth (services are shared and stateless, but worth verifying)
- Connection leaks from the pool
- Error rate drift over time

### 5. Ping Baseline (10 VUs, 60s)
`GET /ping` only — no database, no template rendering. Isolates pure server overhead
(TrueAsync accept loop + request parse + response emit). Provides the theoretical maximum
RPS with no I/O work.

---

## Key Metrics to Capture

| Metric | Description | Target |
|---|---|---|
| `http_req_duration{p50}` | Median latency | < 50ms at low VU counts |
| `http_req_duration{p95}` | 95th percentile latency | < 200ms under moderate load |
| `http_req_duration{p99}` | Tail latency — reveals pool-acquire queuing | watch for spikes |
| `http_req_failed` | Error rate | < 1% |
| `http_reqs` | Requests per second (throughput) | track plateau |
| `iterations` | Total completed scenario iterations | — |

---

## Prerequisites

Before running any `/postgres` load test:
```
GET http://localhost:8080/postgres?action=setup
```
This creates the four tables and seeds them with fixture data from `data/postgres/seed.json`.
Run it once manually or via k6's `setup()` hook before the load starts.
**Never include setup/teardown inside the main iteration loop.**

---

## Tool: k6

[k6](https://k6.io/) is the chosen load testing tool:
- Scripted in JavaScript (ES6 modules)
- Native support for scenarios, thresholds, ramping VUs
- Outputs summary to stdout; can write raw JSON for post-processing

### Installation
```bash
# Debian/Ubuntu (in dev container or CI)
sudo gpg --no-default-keyring \
  --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
  --keyserver hkp://keyserver.ubuntu.com:80 \
  --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
  | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt update && sudo apt install k6

# macOS
brew install k6
```

### Running
```bash
# From workspace root
k6 run test/k6/baseline.js
k6 run test/k6/ramp.js

# Save raw JSON results for analysis
k6 run --out json=test/k6/results/ramp.json test/k6/ramp.js

# Override base URL for CI/staging
BASE_URL=http://staging:8080 k6 run test/k6/ramp.js

# Dry-run syntax check (no actual requests)
k6 run --dry-run test/k6/baseline.js
```

---

## File Layout

```
test/k6/
  baseline.js          # Scenario 1 — 1 VU, 30s, p95<100ms
  ramp.js              # Scenario 2 — 1→20 VUs ramp
  stress.js            # Scenario 3 — 25 VU saturation
  soak.js              # Scenario 4 — 10 VU, 10 min stability
  ping-baseline.js     # Scenario 5 — ping only, p95<10ms
  lib/
    config.js          # Shared BASE_URL, threshold helpers
    checks.js          # Reusable checkOk() helper
  results/             # gitignored raw JSON / CSV output
    .gitignore
```
