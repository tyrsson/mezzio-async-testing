/**
 * Ramp — PDO pool adapter (/postgres/pdo)
 *
 * Hard cap: 10 VUs — equal to the PDO pool max (10). Each request spawns 4
 * concurrent coroutines via TaskGroup, so 10 VUs = 40 simultaneous pool
 * acquires (30 queuing). Beyond 10 VUs coroutine count grows without
 * throughput gain and risks extension instability
 * (see docs/load-testing/results/ramp-crash-2026-04-20.md).
 *
 * Run:
 *   k6 run test/k6/ramp-pdo.js
 *   k6 run --out json=docs/load-testing/results/2026-04-21/ramp-pdo.json test/k6/ramp-pdo.js
 */

import http from 'k6/http';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkOk } from './lib/checks.js';

export const options = {
    thresholds: {
        ...thresholds.normal,
        // Abort immediately if >10% of requests fail (server crash)
        http_req_failed: [{ threshold: 'rate<0.1', abortOnFail: true }],
    },
    scenarios: {
        ramp: {
            executor:  'ramping-vus',
            startVUs:  1,
            stages: [
                { duration: '10s', target: 2  },  // 2 VUs  →  8 slots: no queuing
                { duration: '15s', target: 2  },  // hold   — stable p95 at 2 VUs
                { duration: '10s', target: 3  },  // 3 VUs  → 12 slots: saturation starts
                { duration: '15s', target: 3  },  // hold   — first inflection point
                { duration: '10s', target: 5  },  // 5 VUs  → 20 slots
                { duration: '15s', target: 5  },  // hold
                { duration: '10s', target: 8  },  // 8 VUs  → 32 slots
                { duration: '15s', target: 8  },  // hold
                { duration: '10s', target: 10 },  // 10 VUs → 40 slots: hard ceiling
                { duration: '15s', target: 10 },  // hold   — max throughput measurement
                { duration: '10s', target: 0  },  // cool down
            ],
        },
    },
};

export function setup() {
    const res = http.get(`${BASE_URL}/postgres/pdo?action=setup`);
    if (res.status !== 200) {
        throw new Error(`Setup failed with status ${res.status}: ${res.body}`);
    }
}

export default function () {
    const res = http.get(`${BASE_URL}/postgres/pdo?mode=concurrent`);
    checkOk(res);
}
