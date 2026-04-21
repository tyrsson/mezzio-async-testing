import { check } from 'k6';

/**
 * Assert status 200 and non-empty body.
 * Returns true when all checks pass.
 */
export function checkOk(res) {
    return check(res, {
        'status 200':     (r) => r.status === 200,
        'body not empty': (r) => r.body != null && r.body.length > 0,
    });
}

/**
 * Assert status 200 and Content-Type contains 'text/html'.
 * Use for endpoints that render a Laminas view template.
 */
export function checkHtml(res) {
    return check(res, {
        'status 200':        (r) => r.status === 200,
        'content-type html': (r) => (r.headers['Content-Type'] ?? '').includes('text/html'),
    });
}
