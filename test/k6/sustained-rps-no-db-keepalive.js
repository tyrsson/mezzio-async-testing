/**
 * Sustained throughput — no-DB homepage with HTTP keep-alive (/)
 *
 * The max-rps-no-db-keepalive.js test peaked at ~10,877 req/s but generated
 * 2.17M dropped iterations under extreme overload, inflating latency metrics.
 * This test holds a fixed 9,000 req/s — just below the observed ceiling — to
 * get a clean, stable latency picture at a sustainable load level.
 *
 * Run:
 *   k6 run --out json=docs/load-testing/results/2026-04-22/sustained-rps-no-db-keepalive.json test/k6/sustained-rps-no-db-keepalive.js
 */

import http from 'k6/http';
import { BASE_URL } from './lib/config.js';
import { checkOk } from './lib/checks.js';

export const options = {
    scenarios: {
        sustained: {
            executor: 'constant-arrival-rate',

            rate: 9000,
            timeUnit: '1s',
            duration: '90s',

            preAllocatedVUs: 400,
            maxVUs: 1000,
        },
    },
    thresholds: {
        http_req_failed:   [{ threshold: 'rate<0.01', abortOnFail: true }],
        http_req_duration: ['p(95)<200', 'p(99)<500'],
    },
};

export default function () {
    const res = http.get(`${BASE_URL}/`);
    checkOk(res);
}
