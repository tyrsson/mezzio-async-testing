# Keep-Alive Refactor — k6 Test Plan

## Goal

Confirm that HTTP keep-alive increases throughput for the no-DB endpoint beyond the
4,113 req/s average achieved in `max-rps-no-db-v2.js`, where TCP connection overhead
(`http_req_blocked` avg 67ms) was the dominant cost.

With keep-alive, each k6 VU reuses its connection across iterations. At p95 server
latency of ~8ms, 1 VU can sustain ~125 req/s (1000ms / 8ms). 600 VUs ≈ 75,000 req/s
theoretical — but PHP single-process CPU will be the real ceiling.

---

## New Script: `test/k6/max-rps-no-db-keepalive.js`

k6 VUs use keep-alive by default (`http.setResponseCallback` is not needed). The key
change is using a **lower VU count** — k6's `ramping-arrival-rate` with keep-alive
enabled means each VU can fire many more requests per second than with short connections.

### Configuration

```js
export const options = {
    scenarios: {
        find_max: {
            executor: 'ramping-arrival-rate',
            startRate: 1000,
            timeUnit: '1s',
            preAllocatedVUs: 50,   // far fewer needed — connections are reused
            maxVUs: 200,
            stages: [
                { duration: '15s', target: 1000  },  // warm up
                { duration: '10s', target: 5000  },  // ramp — prev v2 avg ceiling
                { duration: '15s', target: 5000  },  // hold
                { duration: '10s', target: 10000 },  // ramp
                { duration: '15s', target: 10000 },  // hold
                { duration: '10s', target: 20000 },  // ramp
                { duration: '15s', target: 20000 },  // hold
                { duration: '10s', target: 30000 },  // push
                { duration: '15s', target: 30000 },  // hold
                { duration: '10s', target: 40000 },  // hard ceiling attempt
                { duration: '20s', target: 40000 },  // hold
                { duration: '10s', target: 0     },  // cool down
            ],
        },
    },
    thresholds: {
        http_req_failed: [{ threshold: 'rate<0.05', abortOnFail: true }],
        http_req_duration: ['p(99)<2000'],
    },
};
```

### Key difference from `max-rps-no-db-v2.js`

- `maxVUs: 200` instead of 600 — keep-alive means each VU handles many requests
- Target rates start at 5,000 (where v2 was bottlenecked) and push to 40,000
- `http_req_blocked` should drop to near-zero — connection reuse eliminates handshake
- If `http_req_blocked` is still high, the server is closing connections (not honouring keep-alive)

---

## Validation Criteria

The refactor is working correctly if:

| Metric | Expected |
|--------|----------|
| `http_req_blocked` avg | < 1ms (vs 67ms today) |
| Avg req/s | Significantly above 4,113 |
| `http_req_failed` | < 0.05% |
| Server stays up | No crash, no heap corruption |

---

## Regression Tests

Before running the ceiling test, run the existing scripts to confirm no regression:

```bash
# 1. No-DB regression — same profile as before, should match prior numbers
k6 run --out json=docs/load-testing/results/.../max-rps-no-db-regression.json test/k6/max-rps-no-db.js

# 2. pgsql concurrent — keep-alive should also help here (less TCP overhead per request)
k6 run --out json=docs/load-testing/results/.../ramp-pgsql-keepalive.json test/k6/ramp-pgsql.js

# 3. Keep-alive ceiling
k6 run --out json=docs/load-testing/results/.../max-rps-no-db-keepalive.json test/k6/max-rps-no-db-keepalive.js
```

---

## Server-Side Verification

Check the PHP server logs to confirm connections are being reused:

- Multiple requests should appear with the same `$peerName` (IP:port pair)
- Under keep-alive, a single client port will appear in many log lines
- Under short connections, every log line has a different source port

This is observable in `data/psr/log/async.log` or `docker compose logs -f php`.
