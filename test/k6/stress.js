/**
 * Scenario 3 — Pool Saturation Stress
 *
 * Sustained 25 VUs for 60 seconds — well above effective pool capacity
 * (25 VUs × 4 slots per request = 100 simultaneous slots needed, pool max = 10).
 *
 * Pool acquire suspends the coroutine rather than returning an error, so
 * the server should remain stable: requests queue and complete, latency
 * climbs, but the error rate should stay low.
 *
 * Run:
 *   k6 run test/k6/stress.js
 *   k6 run --out json=test/k6/results/stress.json test/k6/stress.js
 *
 * Tip: watch `docker compose logs -f php` for errors during this run.
 */

import http from 'k6/http';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkOk } from './lib/checks.js';

export const options = {
    vus:        25,
    duration:   '30s',
    thresholds: thresholds.relaxed,
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
