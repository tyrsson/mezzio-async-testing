/**
 * Max throughput finder — no-DB homepage with HTTP keep-alive (/)
 *
 * Previous short-connection test (max-rps-no-db-v2.js) peaked at 4,113 req/s avg
 * with http_req_blocked averaging 67ms — TCP handshake overhead was the bottleneck,
 * not the PHP server. With keep-alive, each k6 VU reuses its connection across
 * iterations, eliminating per-request handshake cost.
 *
 * k6 uses HTTP/1.1 keep-alive by default — no special configuration needed.
 * Far fewer VUs are required since each VU can sustain many req/s on one connection.
 *
 * Abort on >5% failures — no DB means failures = server saturation.
 *
 * Run:
 *   k6 run --out json=docs/load-testing/results/2026-04-21/max-rps-no-db-keepalive.json test/k6/max-rps-no-db-keepalive.js
 */

import http from 'k6/http';
import { BASE_URL } from './lib/config.js';
import { checkOk } from './lib/checks.js';

export const options = {
    scenarios: {
        find_max: {
            executor: 'ramping-arrival-rate',

            startRate: 1000,
            timeUnit: '1s',

            // Fewer VUs needed — keep-alive means each VU handles many req/s
            preAllocatedVUs: 1000,
            maxVUs: 3000,

            stages: [
                { duration: '15s', target: 1000  },  // warm up
                { duration: '10s', target: 5000  },  // ramp — prev test avg ceiling
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

export default function () {
    const res = http.get(`${BASE_URL}/`);
    checkOk(res);
}
