/**
 * Incremental push — native pgsql adapter (/postgres/pgsql, mode=baseline)
 *
 * Designed to be run repeatedly with increasing MAX_VUS until the server crashes.
 * Each run steps up to MAX_VUS, holds for 20s, then cools down.
 *
 * Usage:
 *   k6 run -e MAX_VUS=15 test/k6/push-pgsql.js
 *   k6 run -e MAX_VUS=20 --out json=docs/load-testing/results/2026-04-21/push-20vus.json test/k6/push-pgsql.js
 */

import http from 'k6/http';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkOk } from './lib/checks.js';

const MAX_VUS = parseInt(__ENV.MAX_VUS || '15', 10);
const MID_VUS = Math.ceil(MAX_VUS / 2);

export const options = {
    thresholds: {
        ...thresholds.normal,
        http_req_failed: [{ threshold: 'rate<0.1', abortOnFail: true }],
    },
    scenarios: {
        push: {
            executor: 'ramping-vus',
            startVUs: 1,
            stages: [
                { duration: '10s', target: MID_VUS  },  // ramp to midpoint
                { duration: '15s', target: MID_VUS  },  // hold midpoint
                { duration: '10s', target: MAX_VUS  },  // ramp to ceiling
                { duration: '20s', target: MAX_VUS  },  // hold at ceiling — measurement window
                { duration: '10s', target: 0        },  // cool down
            ],
        },
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
