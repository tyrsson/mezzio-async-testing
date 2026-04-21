/**
 * Max throughput finder — no-DB homepage (/)
 *
 * Isolates the PHP/TrueAsync layer from PostgreSQL. If the ceiling here is
 * significantly higher than ~1,700 req/s, the bottleneck in the pgsql tests
 * is PostgreSQL (not the PHP server). If it's also ~1,700, the bottleneck
 * is PHP CPU / the single-process TrueAsync accept loop.
 *
 * Uses the same ramping-arrival-rate profile as max-rps-pgsql.js (Stage 2).
 *
 * Run:
 *   k6 run --out json=docs/load-testing/results/2026-04-21/max-rps-no-db.json test/k6/max-rps-no-db.js
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

            preAllocatedVUs: 150,
            maxVUs: 300,

            stages: [
                { duration: '15s', target: 500  },  // warm up
                { duration: '10s', target: 1000 },  // ramp
                { duration: '15s', target: 1000 },  // hold
                { duration: '10s', target: 1500 },  // ramp
                { duration: '15s', target: 1500 },  // hold
                { duration: '10s', target: 2000 },  // ramp
                { duration: '15s', target: 2000 },  // hold
                { duration: '10s', target: 2500 },  // ramp
                { duration: '15s', target: 2500 },  // hold
                { duration: '10s', target: 3000 },  // ramp
                { duration: '15s', target: 3000 },  // hold
                { duration: '10s', target: 3500 },  // ramp
                { duration: '15s', target: 3500 },  // hold
                { duration: '10s', target: 4000 },  // push to hard ceiling
                { duration: '15s', target: 4000 },  // hold
                { duration: '10s', target: 0    },  // cool down
            ],
        },
    },
    thresholds: {
        http_req_duration: ['p(99)<5000'],
    },
};

export default function () {
    const res = http.get(`${BASE_URL}/`);
    checkOk(res);
}
