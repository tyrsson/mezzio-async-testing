/**
 * Scenario 4 — Soak / Stability
 *
 * 10 VUs with 1 second think time for 10 minutes. Moderate, sustained load
 * designed to surface memory growth, connection leaks, or error-rate drift
 * that only manifest over time.
 *
 * Run:
 *   k6 run test/k6/soak.js
 *   k6 run --out json=test/k6/results/soak.json test/k6/soak.js
 *
 * Monitor alongside:
 *   docker stats   — watch PHP container memory
 *   tail -f data/psr/log/async.log   — watch for error entries
 */

import http from 'k6/http';
import { sleep } from 'k6';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkHtml } from './lib/checks.js';

export const options = {
    vus:        10,
    duration:   '10m',
    thresholds: thresholds.normal,
};

export function setup() {
    const res = http.get(`${BASE_URL}/postgres?action=setup`);
    if (res.status !== 200) {
        throw new Error(`Setup failed with status ${res.status}: ${res.body}`);
    }
}

export default function () {
    const res = http.get(`${BASE_URL}/postgres?mode=soak`);
    checkHtml(res);
    sleep(1);  // 1s think time — keeps ~10 RPS, reduces pool pressure to realistic level
}
