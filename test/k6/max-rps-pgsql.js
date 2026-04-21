/**
 * Max throughput finder — native pgsql adapter (/postgres/pgsql, mode=baseline)
 *
 * Uses ramping-arrival-rate to drive a controlled req/s target rather than VU count.
 * k6 allocates VUs automatically to meet the rate. The server's true max req/s is the
 * point where http_req_failed starts rising (pool exhaustion / overload).
 *
 * Stage 1 (maxVUs=120): peaked at 1,751 req/s — k6 VU-limited, server not saturated.
 * Stage 2 (maxVUs=300): ramps 500 → 4,000 req/s to find the true server ceiling.
 *
 * Run:
 *   k6 run --out json=docs/load-testing/results/2026-04-21/max-rps-stage2.json test/k6/max-rps-pgsql.js
 */

import http from 'k6/http';
import { BASE_URL } from './lib/config.js';
import { checkOk } from './lib/checks.js';

export const options = {
    scenarios: {
        find_max: {
            executor: 'ramping-arrival-rate',

            startRate: 500,
            timeUnit: '1s',

            // Stage 2: 300 VUs — enough headroom to reach ~3,000+ req/s
            preAllocatedVUs: 150,
            maxVUs: 300,

            stages: [
                { duration: '15s', target: 500  },  // warm up
                { duration: '10s', target: 1000 },  // ramp
                { duration: '15s', target: 1000 },  // hold
                { duration: '10s', target: 1500 },  // ramp
                { duration: '15s', target: 1500 },  // hold — stage 1 safe ceiling
                { duration: '10s', target: 2000 },  // ramp — stage 1 VU-limited zone
                { duration: '15s', target: 2000 },  // hold
                { duration: '10s', target: 2500 },  // ramp
                { duration: '15s', target: 2500 },  // hold
                { duration: '10s', target: 3000 },  // ramp — theoretical pool ceiling
                { duration: '15s', target: 3000 },  // hold
                { duration: '10s', target: 3500 },  // ramp — beyond pool ceiling
                { duration: '15s', target: 3500 },  // hold — expect degradation
                { duration: '10s', target: 4000 },  // push to hard ceiling
                { duration: '15s', target: 4000 },  // hold
                { duration: '10s', target: 0    },  // cool down
            ],
        },
    },
    thresholds: {
        // Don't abort — we WANT to see where it breaks. Just record everything.
        http_req_duration: ['p(99)<5000'],
    },
};

export function setup() {
    const res = http.get(`${BASE_URL}/postgres/pgsql?action=setup`);
    if (res.status !== 200) {
        throw new Error(`Setup failed with status ${res.status}: ${res.body}`);
    }
}

export default function () {
    const res = http.get(`${BASE_URL}/postgres/pgsql?mode=baseline`);
    checkOk(res);
}
