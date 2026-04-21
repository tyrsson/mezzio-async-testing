<?php

/**
 * Stub for PDO pool constants added by the true_async extension (PHP 8.6+).
 *
 * These constants are baked into the PDO class by ext-true_async and are not
 * present in PHPStan's bundled PDO stubs. Values verified against the extension.
 *
 * @see https://true-async.github.io/en/docs/components/pdo-pool.html
 */
class PDO
{
    /** Enable PDO connection pooling for TrueAsync coroutines. */
    public const int ATTR_POOL_ENABLED = 22;
    /** Minimum number of connections to keep in the pool. */
    public const int ATTR_POOL_MIN = 23;
    /** Maximum number of connections allowed in the pool. */
    public const int ATTR_POOL_MAX = 24;
    /** Pool health-check interval in seconds. */
    public const int ATTR_POOL_HEALTHCHECK_INTERVAL = 25;
}
