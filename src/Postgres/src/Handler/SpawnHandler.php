<?php

declare(strict_types=1);

/**
 * This file is part of the Webware Mezzio Bleeding Edge package.
 *
 * Copyright (c) 2026 Joey Smith <jsmith@webinertia.net>
 * and contributors.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Postgres\Handler;

use Laminas\Diactoros\Response;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Async\Adapter;
use PhpDb\Async\Profiler\Profiler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function array_keys;
use function array_values;
use function compact;
use function hrtime;
use function round;
use function spawn;
use function await_all_or_fail;

/**
 * Benchmark handler using spawn() + await_all_or_fail() for concurrent queries.
 *
 * This is the pre-TaskGroup approach (commit a66c1b1) used to isolate whether
 * zend_mm_heap corruption is specific to Async\TaskGroup or affects all
 * concurrent spawn patterns.
 *
 * Route: GET /postgres/spawn[?mode=concurrent|baseline]
 */
final class SpawnHandler implements RequestHandlerInterface
{
    /** @var array<string, string> */
    private const QUERIES = [
        'Users (top 20 by id)'             =>
            'SELECT id, username, email, created_at
               FROM bm_users
              ORDER BY id
              LIMIT 20',
        'Products (top 20 by price)'       =>
            'SELECT id, name, price, stock
               FROM bm_products
              ORDER BY price DESC
              LIMIT 20',
        'Recent orders (top 20)'           =>
            'SELECT o.id, o.total, o.status, o.created_at, u.username
               FROM bm_orders o
               JOIN bm_users u ON u.id = o.user_id
              ORDER BY o.created_at DESC
              LIMIT 20',
        'Top products by revenue (top 10)' =>
            'SELECT p.name,
                    SUM(oi.quantity * oi.unit_price) AS revenue,
                    SUM(oi.quantity) AS units_sold
               FROM bm_order_items oi
               JOIN bm_products p ON p.id = oi.product_id
              GROUP BY p.id, p.name
              ORDER BY revenue DESC
              LIMIT 10',
    ];

    public function __construct(
        private readonly ?Adapter $dbAdapter = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->dbAdapter instanceof Adapter) {
            return new Response\JsonResponse(['error' => 'No pgsql adapter configured'], 503);
        }

        $params = $request->getQueryParams();
        $mode   = $params['mode'] ?? 'concurrent';

        $profilers = [];
        foreach (array_keys(self::QUERIES) as $label) {
            $profilers[$label] = new Profiler();
        }

        $startNs = hrtime(true);

        try {
            $results = $mode === 'baseline'
                ? $this->runSequentially($profilers)
                : $this->runWithSpawn($profilers);

            $totalMs = round((hrtime(true) - $startNs) / 1_000_000, 2);

            $queryProfiles = [];
            foreach ($profilers as $label => $profiler) {
                $profile         = $profiler->getLastProfile();
                $queryProfiles[] = [
                    'label'      => $label,
                    'sql'        => $profile['sql'] ?? '',
                    'elapsed_ms' => $profile['elapsed_ms'] ?? null,
                    'wall_start' => $profile['wall_start'] ?? null,
                ];
            }

            return new Response\JsonResponse([
                'users'                   => $results['users'],
                'products'                => $results['products'],
                'recent_orders'           => $results['orders'],
                'top_products_by_revenue' => $results['topProducts'],
                'profiles'                => $queryProfiles,
                'total_ms'                => $totalMs,
            ]);
        } catch (Throwable $e) {
            return new Response\JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Pre-TaskGroup concurrent approach: unscoped spawn() + await_all_or_fail().
     * Used to determine whether heap corruption is TaskGroup-specific.
     *
     * @param array<string, Profiler> $profilers
     * @return array{users: array, products: array, orders: array, topProducts: array}
     */
    private function runWithSpawn(array $profilers): array
    {
        $sqls  = array_values(self::QUERIES);
        $profs = array_values($profilers);

        $coroutines = [];
        foreach ($sqls as $i => $sql) {
            $prof         = $profs[$i];
            $coroutines[] = spawn(fn() => $this->fetchRows($sql, $prof));
        }

        [$users, $products, $orders, $topProducts] = await_all_or_fail($coroutines);

        return compact('users', 'products', 'orders', 'topProducts');
    }

    /**
     * @param array<string, Profiler> $profilers
     * @return array{users: array, products: array, orders: array, topProducts: array}
     */
    private function runSequentially(array $profilers): array
    {
        $sqls  = array_values(self::QUERIES);
        $profs = array_values($profilers);

        $users       = $this->fetchRows($sqls[0], $profs[0]);
        $products    = $this->fetchRows($sqls[1], $profs[1]);
        $orders      = $this->fetchRows($sqls[2], $profs[2]);
        $topProducts = $this->fetchRows($sqls[3], $profs[3]);

        return compact('users', 'products', 'orders', 'topProducts');
    }

    private function fetchRows(string $sql, Profiler $profiler): array
    {
        $result = $this->dbAdapter->query($sql, AdapterInterface::QUERY_MODE_QUERY, $profiler);
        return $result->toArray();
    }
}
