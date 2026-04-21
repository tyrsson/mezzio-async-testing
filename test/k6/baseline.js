/**
 * Scenario 1 — Baseline (sequential)
 *
 * Single VU, 30 seconds, no think time.
 * Runs all four queries sequentially (no coroutines) so totalMs ≈ sum of the
 * individual query elapsed_ms values.  Compare against ?mode=concurrent to
 * confirm that coroutine concurrency is actually overlapping I/O.
 *
 * Tests both the native pgsql adapter (/postgres/pgsql) and the PDO pool
 * adapter (/postgres/pdo) sequentially to establish baseline for each.
 *
 * Run:
 *   k6 run test/k6/baseline.js
 */

import http from 'k6/http';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkHtml } from './lib/checks.js';

export const options = {
    vus:        1,
    duration:   '15s',
    thresholds: thresholds.tight,
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
    const pgsql = http.get(`${BASE_URL}/postgres/pgsql?mode=baseline`);
    checkHtml(pgsql);

    const pdo = http.get(`${BASE_URL}/postgres/pdo?mode=baseline`);
    checkHtml(pdo);
}
