/**
 * Scenario 2 — Concurrency Ramp (capped at 10 VUs)
 *
 * Fine-grained ramp to find the maximum throughput at an acceptable p95 latency.
 * Hard cap: 10 VUs — equal to the DB pool max. Beyond this point each additional
 * VU only adds pool-acquire queuing, not throughput.
 *
 * Each request needs up to 4 pool slots concurrently:
 *   2 VUs →  8 slots  (within pool, no queuing)
 *   3 VUs → 12 slots  (2 queue, queuing begins — expect first p95 climb)
 *   5 VUs → 20 slots  (10 queue)
 *   8 VUs → 32 slots  (22 queue)
 *  10 VUs → 40 slots  (30 queue — maximum useful ceiling)
 *
 * Each VU hits both /postgres/pgsql and /postgres/pdo (?mode=stress for JSON-only,
 * no template overhead) so both adapters are compared under identical load.
 *
 * Run:
 *   k6 run test/k6/ramp.js
 *   k6 run --out json=test/k6/results/ramp.json test/k6/ramp.js
 */

import http from 'k6/http';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkOk } from './lib/checks.js';

export const options = {
    thresholds: thresholds.normal,
    scenarios: {
        ramp: {
            executor:  'ramping-vus',
            startVUs:  1,
            stages: [
                { duration: '10s', target: 2  },  // 2 VUs  →  8 slots: baseline, no queuing
                { duration: '15s', target: 2  },  // hold   — read stable p95 at 2 VUs
                { duration: '10s', target: 3  },  // 3 VUs  → 12 slots: pool saturation starts
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
    const pgsql = http.get(`${BASE_URL}/postgres/pgsql?action=setup`);
    if (pgsql.status !== 200) {
        throw new Error(`pgsql setup failed with status ${pgsql.status}: ${pgsql.body}`);
    }
    const pdo = http.get(`${BASE_URL}/postgres/pdo?action=setup`);
    if (pdo.status !== 200) {
        throw new Error(`pdo setup failed with status ${pdo.status}: ${pdo.body}`);
    }
}

export default function () {
    const pgsql = http.get(`${BASE_URL}/postgres/pgsql?mode=stress`);
    checkOk(pgsql);

    const pdo = http.get(`${BASE_URL}/postgres/pdo?mode=stress`);
    checkOk(pdo);
}
