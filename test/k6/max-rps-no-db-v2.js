/**
 * Max throughput finder v2 — no-DB homepage (/)
 *
 * The previous test (max-rps-no-db.js) only targeted 4,000 req/s and never
 * saturated the server — peak was 7,610 req/s in a burst with zero failures
 * and only 101/150 VUs active. This script pushes to 15,000 req/s to find the
 * true TrueAsync accept-loop / single-process ceiling.
 *
 * At sub-millisecond latency, VU count needed to sustain N req/s is low
 * (N × avg_latency_s), but connection overhead and OS TCP stack become the
 * real constraint. 600 maxVUs provides headroom well beyond the expected ceiling.
 *
 * Abort on >5% failures — no DB means the server should not be degrading;
 * failures indicate the accept loop / OS socket queue is exhausted.
 *
 * Run:
 *   k6 run --out json=docs/load-testing/results/2026-04-21/max-rps-no-db-v2.json test/k6/max-rps-no-db-v2.js
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

            preAllocatedVUs: 200,
            maxVUs: 600,

            stages: [
                { duration: '15s', target: 1000  },  // warm up — well below prev ceiling
                { duration: '10s', target: 2000  },  // ramp
                { duration: '15s', target: 2000  },  // hold — prev test steady state
                { duration: '10s', target: 4000  },  // ramp — prev test max target
                { duration: '15s', target: 4000  },  // hold — baseline confirmed stable
                { duration: '10s', target: 6000  },  // ramp — above prev burst peak
                { duration: '15s', target: 6000  },  // hold
                { duration: '10s', target: 8000  },  // ramp
                { duration: '15s', target: 8000  },  // hold
                { duration: '10s', target: 10000 },  // ramp
                { duration: '15s', target: 10000 },  // hold
                { duration: '10s', target: 12000 },  // ramp
                { duration: '15s', target: 12000 },  // hold
                { duration: '10s', target: 15000 },  // push to hard ceiling
                { duration: '20s', target: 15000 },  // hold — measure degradation onset
                { duration: '10s', target: 0     },  // cool down
            ],
        },
    },
    thresholds: {
        // Abort if >5% fail — no DB means failures = server saturation, not pool exhaustion
        http_req_failed: [{ threshold: 'rate<0.05', abortOnFail: true }],
        // Record latency up to the ceiling
        http_req_duration: ['p(99)<2000'],
    },
};

export default function () {
    const res = http.get(`${BASE_URL}/`);
    checkOk(res);
}
