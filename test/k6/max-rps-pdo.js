/**
 * Max throughput finder — PDO pool adapter (/postgres/pdo, mode=concurrent)
 *
 * Mirrors max-rps-pgsql.js but targets the PDO adapter with concurrent TaskGroup
 * queries. Uses ramping-arrival-rate to drive a controlled req/s target.
 *
 * Unlike max-rps-pgsql.js, this test aborts if the failure rate exceeds 10% —
 * the PDO adapter is less battle-tested so we stop rather than push through failure.
 *
 * Run:
 *   k6 run test/k6/max-rps-pdo.js
 *   k6 run --out json=docs/load-testing/results/2026-04-21/max-rps-pdo.json test/k6/max-rps-pdo.js
 */

import http from 'k6/http';
import { BASE_URL } from './lib/config.js';
import { checkOk } from './lib/checks.js';

export const options = {
    scenarios: {
        find_max: {
            executor: 'ramping-arrival-rate',

            startRate: 200,
            timeUnit: '1s',

            // 300 VUs — same headroom as the pgsql ceiling test
            preAllocatedVUs: 150,
            maxVUs: 300,

            stages: [
                { duration: '15s', target: 200  },  // warm up
                { duration: '10s', target: 500  },  // ramp
                { duration: '15s', target: 500  },  // hold
                { duration: '10s', target: 1000 },  // ramp
                { duration: '15s', target: 1000 },  // hold
                { duration: '10s', target: 1500 },  // ramp
                { duration: '15s', target: 1500 },  // hold
                { duration: '10s', target: 2000 },  // ramp
                { duration: '15s', target: 2000 },  // hold
                { duration: '10s', target: 2500 },  // ramp
                { duration: '15s', target: 2500 },  // hold
                { duration: '10s', target: 3000 },  // ramp — pgsql peak zone
                { duration: '15s', target: 3000 },  // hold
                { duration: '10s', target: 3500 },  // push beyond
                { duration: '15s', target: 3500 },  // hold
                { duration: '10s', target: 0    },  // cool down
            ],
        },
    },
    thresholds: {
        // Abort if >10% of requests fail — stop rather than push through instability
        http_req_failed: [{ threshold: 'rate<0.1', abortOnFail: true }],
        // Record everything up to the failure point
        http_req_duration: ['p(99)<5000'],
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
