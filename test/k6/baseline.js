/**
 * Scenario 1 — Baseline (sequential)
 *
 * Single VU, 30 seconds, no think time.
 * Runs all four queries sequentially (no coroutines) so totalMs ≈ sum of the
 * individual query elapsed_ms values.  Compare against ?mode=concurrent to
 * confirm that coroutine concurrency is actually overlapping I/O.
 *
 * Run:
 *   k6 run test/k6/baseline.js
 */

import http from 'k6/http';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkHtml } from './lib/checks.js';

export const options = {
    vus:        1,
    duration:   '30s',
    thresholds: thresholds.tight,
};

export function setup() {
    const res = http.get(`${BASE_URL}/postgres?action=setup`);
    if (res.status !== 200) {
        throw new Error(`Setup failed with status ${res.status}: ${res.body}`);
    }
}

export default function () {
    const res = http.get(`${BASE_URL}/postgres?mode=baseline`);
    checkHtml(res);
}
