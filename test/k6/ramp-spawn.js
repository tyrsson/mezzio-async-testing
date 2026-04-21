/**
 * Ramp — spawn() + await_all_or_fail() (/postgres/spawn)
 *
 * Isolation test: determines whether zend_mm_heap corruption is specific to
 * Async\TaskGroup or affects all concurrent spawn patterns.
 * See docs/load-testing/results/2026-04-21/taskgroup.md for context.
 *
 * Run:
 *   k6 run test/k6/ramp-spawn.js
 *   k6 run --out json=docs/load-testing/results/2026-04-21/ramp-spawn.json test/k6/ramp-spawn.js
 */

import http from 'k6/http';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkOk } from './lib/checks.js';

export const options = {
    thresholds: {
        ...thresholds.normal,
        http_req_failed: [{ threshold: 'rate<0.1', abortOnFail: true }],
    },
    scenarios: {
        ramp: {
            executor:  'ramping-vus',
            startVUs:  1,
            stages: [
                { duration: '10s', target: 2  },
                { duration: '15s', target: 2  },
                { duration: '10s', target: 3  },
                { duration: '15s', target: 3  },
                { duration: '10s', target: 5  },
                { duration: '15s', target: 5  },
                { duration: '10s', target: 8  },
                { duration: '15s', target: 8  },
                { duration: '10s', target: 10 },
                { duration: '15s', target: 10 },
                { duration: '10s', target: 0  },
            ],
        },
    },
};

export default function () {
    const res = http.get(`${BASE_URL}/postgres/spawn?mode=concurrent`);
    checkOk(res);
}
