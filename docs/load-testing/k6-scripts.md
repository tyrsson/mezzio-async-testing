# Load Testing: k6 Script Designs

Reference: [overview.md](./overview.md)

All scripts live in `test/k6/`. Shared helpers are in `test/k6/lib/`.

---

## lib/config.js

Centralises the base URL (overridable via environment variable) and suggested
threshold values so individual scripts stay DRY.

```js
export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

export const thresholds = {
  // Used by baseline and ramp
  tight: {
    http_req_failed:  ['rate<0.001'],
    http_req_duration: ['p(95)<100', 'p(99)<200'],
  },
  // Used by ramp and soak
  normal: {
    http_req_failed:  ['rate<0.01'],
    http_req_duration: ['p(95)<500'],
  },
  // Used by stress — pool saturation expected; latency will climb
  relaxed: {
    http_req_failed:  ['rate<0.05'],
    http_req_duration: ['p(95)<2000'],
  },
  // Used by ping baseline — no DB, near-zero overhead
  ping: {
    http_req_failed:  ['rate<0.001'],
    http_req_duration: ['p(95)<10'],
  },
};
```

---

## lib/checks.js

```js
import { check } from 'k6';

/**
 * Assert response status is 200 and body is non-empty.
 * Returns true if all checks pass (for use with Rate metric).
 */
export function checkOk(res) {
  return check(res, {
    'status 200':      (r) => r.status === 200,
    'body not empty':  (r) => r.body != null && r.body.length > 0,
  });
}

/**
 * Assert response status is 200 and Content-Type contains 'text/html'.
 */
export function checkHtml(res) {
  return check(res, {
    'status 200':        (r) => r.status === 200,
    'content-type html': (r) => r.headers['Content-Type']
                                  ?.includes('text/html') ?? false,
  });
}
```

---

## baseline.js — Scenario 1

**Purpose:** Single VU, minimum latency, verify profiler data shape.

```js
import http from 'k6/http';
import { sleep } from 'k6';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkHtml } from './lib/checks.js';

export const options = {
  vus:      1,
  duration: '30s',
  thresholds: thresholds.tight,
};

export function setup() {
  // Ensure tables exist and are seeded before the test starts.
  const res = http.get(`${BASE_URL}/postgres?action=setup`);
  if (res.status !== 200) {
    throw new Error(`Setup failed: ${res.status} ${res.body}`);
  }
}

export default function () {
  const res = http.get(`${BASE_URL}/postgres`);
  checkHtml(res);
  // No sleep — measure raw sequential throughput at 1 VU.
}
```

**What to observe:**
- p50 / p95 should both be < 100ms on a warm pool
- `totalMs` in the HTML body should be ≈ `max(individual query elapsed_ms)`,
  not their sum — this confirms the four coroutines overlapped

---

## ramp.js — Scenario 2

**Purpose:** Gradually increase VUs to locate the pool saturation inflection point.

```js
import http from 'k6/http';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkHtml } from './lib/checks.js';

export const options = {
  thresholds: thresholds.normal,
  scenarios: {
    ramp: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '30s', target: 5  },  // warm up
        { duration: '60s', target: 10 },  // approach pool limit
        { duration: '30s', target: 20 },  // exceed pool limit
        { duration: '20s', target: 0  },  // cool down
      ],
    },
  },
};

export function setup() {
  const res = http.get(`${BASE_URL}/postgres?action=setup`);
  if (res.status !== 200) {
    throw new Error(`Setup failed: ${res.status}`);
  }
}

export default function () {
  const res = http.get(`${BASE_URL}/postgres`);
  checkHtml(res);
}
```

**What to observe:**
- p95 inflection point — the VU count where latency begins climbing sharply
- Expected: latency increases noticeably around 3 VUs (3 × 4 slots = 12 > pool max 10),
  then again around 5–10 VUs as pool queuing deepens
- Error rate should stay near zero (pool queues, does not reject)

---

## stress.js — Scenario 3

**Purpose:** Sustained load well above pool capacity. Observes server stability under pressure.

```js
import http from 'k6/http';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkOk } from './lib/checks.js';

export const options = {
  vus:      25,
  duration: '60s',
  thresholds: thresholds.relaxed,
};

// Assumes setup already run — skip here to avoid inflating latency metrics.
export default function () {
  const res = http.get(`${BASE_URL}/postgres`);
  checkOk(res);
}
```

**What to observe:**
- Throughput plateau (RPS will be capped by pool slot availability)
- p99 tail latency — should climb but not cause outright failures
- Error rate — should remain < 5% even at 25 VUs if pool queuing works correctly
- Server stability: watch `docker compose logs php` for panics or OOM

---

## soak.js — Scenario 4

**Purpose:** 10-minute sustained run at moderate load to detect leaks and drift.

```js
import http from 'k6/http';
import { sleep } from 'k6';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkHtml } from './lib/checks.js';

export const options = {
  vus:      10,
  duration: '10m',
  thresholds: thresholds.normal,
};

export function setup() {
  const res = http.get(`${BASE_URL}/postgres?action=setup`);
  if (res.status !== 200) {
    throw new Error(`Setup failed: ${res.status}`);
  }
}

export default function () {
  const res = http.get(`${BASE_URL}/postgres`);
  checkHtml(res);
  sleep(1);  // 1s think time keeps RPS reasonable, reduces pool pressure
}
```

**What to observe:**
- Error rate over time — should not drift upward
- p95 over time — should remain stable (no memory-pressure-induced slowdown)
- `docker stats` PHP container memory — should not grow unboundedly
- Log file size (static assets are excluded; only app requests logged)

---

## ping-baseline.js — Scenario 5

**Purpose:** Measure pure server overhead with no DB or template work.

```js
import http from 'k6/http';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkOk } from './lib/checks.js';

export const options = {
  vus:      10,
  duration: '60s',
  thresholds: thresholds.ping,
};

export default function () {
  const res = http.get(`${BASE_URL}/ping`);
  checkOk(res);
}
```

**What to observe:**
- p95 should be < 10ms — any higher suggests accept loop or connection handling overhead
- RPS ceiling for the server with zero I/O wait
- Compare this RPS against `/postgres` RPS to quantify DB overhead

---

## Interpreting Results

### Pool saturation signature
In the ramp scenario, when the p95 curve has a distinct "knee" (sudden steepening), that is
pool acquire queuing beginning. The VU count at the knee × 4 ≈ pool max.

### Concurrency efficiency
From the baseline run, compare profiler data in the HTML response:
- If `totalMs ≈ max(query_elapsed_ms)`: coroutines overlapped — good
- If `totalMs ≈ sum(query_elapsed_ms)`: queries ran sequentially — something is blocking

### RPS vs VU count
Plot RPS from ramp results. It should increase linearly up to the pool saturation point,
then flatten. The slope before saturation divided by the slope after is the "efficiency cliff".

---

## Running All Scenarios in Sequence

```bash
# 1. Prerequisites
curl -s "http://localhost:8080/postgres?action=setup" | head -c 200

# 2. Baseline
k6 run --out json=test/k6/results/baseline.json test/k6/baseline.js

# 3. Ramp
k6 run --out json=test/k6/results/ramp.json test/k6/ramp.js

# 4. Stress
k6 run --out json=test/k6/results/stress.json test/k6/stress.js

# 5. Soak (long — run in background or tmux)
k6 run --out json=test/k6/results/soak.json test/k6/soak.js

# 6. Ping baseline
k6 run --out json=test/k6/results/ping.json test/k6/ping-baseline.js
```
