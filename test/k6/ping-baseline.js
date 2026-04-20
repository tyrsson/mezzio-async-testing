/**
 * Scenario 5 — Ping Baseline
 *
 * 10 VUs against GET /ping for 60 seconds. No database, no template rendering —
 * measures pure TrueAsync server overhead: accept loop, request parse, response
 * emit. Provides the theoretical RPS ceiling with zero I/O wait.
 *
 * Compare the RPS here against the /postgres RPS from ramp.js to quantify the
 * cost of the DB + template path.
 *
 * Run:
 *   k6 run test/k6/ping-baseline.js
 */

import http from 'k6/http';
import { BASE_URL, thresholds } from './lib/config.js';
import { checkOk } from './lib/checks.js';

export const options = {
    vus:        10,
    duration:   '60s',
    thresholds: thresholds.ping,
};

export default function () {
    const res = http.get(`${BASE_URL}/ping`);
    checkOk(res);
}
