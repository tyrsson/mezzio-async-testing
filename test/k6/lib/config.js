/**
 * Shared configuration for mezzio-async k6 load tests.
 *
 * Override BASE_URL at the CLI:
 *   BASE_URL=http://staging:8080 k6 run test/k6/baseline.js
 */

export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

export const thresholds = {
    // Baseline / ping — tight budget, warm pool, single VU
    tight: {
        http_req_failed:   ['rate<0.001'],
        http_req_duration: ['p(95)<100', 'p(99)<200'],
    },
    // Ramp / soak — moderate load, some pool contention expected
    normal: {
        http_req_failed:   ['rate<0.01'],
        http_req_duration: ['p(95)<500'],
    },
    // Stress — pool saturation intentional; latency will climb
    relaxed: {
        http_req_failed:   ['rate<0.05'],
        http_req_duration: ['p(95)<2000'],
    },
    // Ping — no DB, sub-millisecond server overhead expected
    ping: {
        http_req_failed:   ['rate<0.001'],
        http_req_duration: ['p(95)<10'],
    },
};
