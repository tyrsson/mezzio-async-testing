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
    duration:   '60s',
    thresholds: thresholds.relaxed,
};

// Assumes setup was already run (e.g. via baseline.js or manually).
// Including setup here would inflate the early-iteration latency metrics.
export default function () {
    const res = http.get(`${BASE_URL}/postgres?mode=stress`);
    checkOk(res);
}
