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
    duration:   '5m',
    thresholds: thresholds.normal,
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
    const pgsql = http.get(`${BASE_URL}/postgres/pgsql?mode=concurrent`);
    checkHtml(pgsql);

    const pdo = http.get(`${BASE_URL}/postgres/pdo?mode=concurrent`);
    checkHtml(pdo);

    sleep(1);  // 1s think time — keeps load moderate, surfaces leaks over time
}
