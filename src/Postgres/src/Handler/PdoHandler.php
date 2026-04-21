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

use Async\TaskGroup;
use Laminas\Diactoros\Response;
use Mezzio\Template\TemplateRendererInterface;
use PhpDb\Adapter\Adapter;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Async\Profiler\Profiler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

use function array_keys;
use function array_values;
use function compact;
use function count;
use function file_get_contents;
use function hrtime;
use function json_decode;
use function round;

use const JSON_THROW_ON_ERROR;

/**
 * Benchmark handler using the PDO pool adapter (PDO::ATTR_POOL_ENABLED).
 *
 * Route: GET /postgres/pdo[?action=setup|teardown&mode=concurrent|baseline|stress]
 */
final class PdoHandler implements RequestHandlerInterface
{
    private const SEED_FILE = __DIR__ . '/../../../../data/postgres/seed.json';

    /**
     * Benchmark execution modes.
     *
     * concurrent  — 4 queries as TaskGroup coroutines; HTML response
     * baseline    — 4 queries sequentially; proves totalMs ≈ sum(query_ms)
     * stress      — concurrent; JSON only (no template overhead)
     */
    private const DEFAULT_MODES = [
        'concurrent' => ['concurrent' => true,  'response' => 'html'],
        'baseline'   => ['concurrent' => false, 'response' => 'html'],
        'stress'     => ['concurrent' => true,  'response' => 'json'],
    ];

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

    private const DDL_USERS = <<<SQL
        CREATE TABLE IF NOT EXISTS bm_users (
            id          BIGSERIAL    PRIMARY KEY,
            username    VARCHAR(80)  NOT NULL,
            email       VARCHAR(200) NOT NULL,
            created_at  TIMESTAMP    NOT NULL DEFAULT NOW()
        )
        SQL;

    private const DDL_PRODUCTS = <<<SQL
        CREATE TABLE IF NOT EXISTS bm_products (
            id          BIGSERIAL      PRIMARY KEY,
            name        VARCHAR(200)   NOT NULL,
            price       DECIMAL(10,2)  NOT NULL,
            stock       INTEGER        NOT NULL DEFAULT 0,
            created_at  TIMESTAMP      NOT NULL DEFAULT NOW()
        )
        SQL;

    private const DDL_ORDERS = <<<SQL
        CREATE TABLE IF NOT EXISTS bm_orders (
            id          BIGSERIAL      PRIMARY KEY,
            user_id     BIGINT         NOT NULL REFERENCES bm_users(id) ON DELETE CASCADE,
            total       DECIMAL(10,2)  NOT NULL,
            status      VARCHAR(20)    NOT NULL DEFAULT 'pending',
            created_at  TIMESTAMP      NOT NULL DEFAULT NOW()
        )
        SQL;

    private const DDL_ORDER_ITEMS = <<<SQL
        CREATE TABLE IF NOT EXISTS bm_order_items (
            id           BIGSERIAL      PRIMARY KEY,
            order_id     BIGINT         NOT NULL REFERENCES bm_orders(id) ON DELETE CASCADE,
            product_id   BIGINT         NOT NULL REFERENCES bm_products(id),
            quantity     INTEGER        NOT NULL DEFAULT 1,
            unit_price   DECIMAL(10,2)  NOT NULL
        )
        SQL;

    public function __construct(
        private readonly ?TemplateRendererInterface $template = null,
        private readonly ?Adapter $dbAdapter = null,
        private readonly array $benchmarkModes = [],
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->dbAdapter instanceof Adapter) {
            return new Response\JsonResponse(['error' => 'No PDO adapter configured'], 503);
        }

        $params = $request->getQueryParams();
        $action = $params['action'] ?? 'query';
        $mode   = $params['mode']   ?? 'concurrent';

        return match ($action) {
            'setup'    => $this->setup(),
            'teardown' => $this->teardown(),
            default    => $this->query($mode),
        };
    }

    private function setup(): ResponseInterface
    {
        foreach ([self::DDL_USERS, self::DDL_PRODUCTS, self::DDL_ORDERS, self::DDL_ORDER_ITEMS] as $ddl) {
            $this->dbAdapter->query($ddl, AdapterInterface::QUERY_MODE_EXECUTE);
        }

        $seed   = $this->loadSeed();
        $counts = $this->seed($seed);

        return new Response\JsonResponse(['tables_created' => true, 'seeded' => $counts]);
    }

    private function teardown(): ResponseInterface
    {
        foreach (['bm_order_items', 'bm_orders', 'bm_products', 'bm_users'] as $table) {
            $this->dbAdapter->query(
                "DROP TABLE IF EXISTS {$table}",
                AdapterInterface::QUERY_MODE_EXECUTE
            );
        }

        return new Response\JsonResponse(['tables_dropped' => true]);
    }

    private function loadSeed(): array
    {
        $json = file_get_contents(self::SEED_FILE);
        if ($json === false) {
            throw new RuntimeException('Cannot read seed file: ' . self::SEED_FILE);
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function seed(array $data): array
    {
        $this->dbAdapter->query(
            'TRUNCATE bm_order_items, bm_orders, bm_products, bm_users RESTART IDENTITY CASCADE',
            AdapterInterface::QUERY_MODE_EXECUTE
        );

        $p = $this->dbAdapter->getPlatform();

        foreach ($data['users'] as $row) {
            $this->dbAdapter->query(
                sprintf(
                    'INSERT INTO bm_users (username, email, created_at) VALUES (%s, %s, %s)',
                    $p->quoteValue((string) $row['username']),
                    $p->quoteValue((string) $row['email']),
                    $p->quoteValue((string) $row['created_at']),
                ),
                AdapterInterface::QUERY_MODE_EXECUTE
            );
        }

        foreach ($data['products'] as $row) {
            $this->dbAdapter->query(
                sprintf(
                    'INSERT INTO bm_products (name, price, stock, created_at) VALUES (%s, %s, %s, %s)',
                    $p->quoteValue((string) $row['name']),
                    $p->quoteValue((string) $row['price']),
                    $p->quoteValue((string) $row['stock']),
                    $p->quoteValue((string) $row['created_at']),
                ),
                AdapterInterface::QUERY_MODE_EXECUTE
            );
        }

        foreach ($data['orders'] as $row) {
            $this->dbAdapter->query(
                sprintf(
                    'INSERT INTO bm_orders (user_id, total, status, created_at) VALUES (%s, %s, %s, %s)',
                    $p->quoteValue((string) $row['user_id']),
                    $p->quoteValue((string) $row['total']),
                    $p->quoteValue((string) $row['status']),
                    $p->quoteValue((string) $row['created_at']),
                ),
                AdapterInterface::QUERY_MODE_EXECUTE
            );
        }

        foreach ($data['order_items'] as $row) {
            $this->dbAdapter->query(
                sprintf(
                    'INSERT INTO bm_order_items (order_id, product_id, quantity, unit_price) VALUES (%s, %s, %s, %s)',
                    $p->quoteValue((string) $row['order_id']),
                    $p->quoteValue((string) $row['product_id']),
                    $p->quoteValue((string) $row['quantity']),
                    $p->quoteValue((string) $row['unit_price']),
                ),
                AdapterInterface::QUERY_MODE_EXECUTE
            );
        }

        return [
            'users'       => count($data['users']),
            'products'    => count($data['products']),
            'orders'      => count($data['orders']),
            'order_items' => count($data['order_items']),
        ];
    }

    private function query(string $mode): ResponseInterface
    {
        $modes  = $this->benchmarkModes !== [] ? $this->benchmarkModes : self::DEFAULT_MODES;
        $config = $modes[$mode] ?? $modes['concurrent'];

        $profilers = [];
        foreach (array_keys(self::QUERIES) as $label) {
            $profilers[$label] = new Profiler();
        }

        $startNs = hrtime(true);

        try {
            $results = ($config['concurrent'] ?? true)
                ? $this->runConcurrently($profilers)
                : $this->runSequentially($profilers);

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

            return $this->renderResponse($results, $queryProfiles, $totalMs, $config['response'] ?? 'html');
        } catch (Throwable $e) {
            return new Response\JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @param array<string, Profiler> $profilers
     * @return array{users: array, products: array, orders: array, topProducts: array}
     */
    private function runConcurrently(array $profilers): array
    {
        $group = new TaskGroup();

        $group->spawnWithKey('users',       fn() => $this->fetchRows(self::QUERIES['Users (top 20 by id)'],              $profilers['Users (top 20 by id)']));
        $group->spawnWithKey('products',    fn() => $this->fetchRows(self::QUERIES['Products (top 20 by price)'],        $profilers['Products (top 20 by price)']));
        $group->spawnWithKey('orders',      fn() => $this->fetchRows(self::QUERIES['Recent orders (top 20)'],            $profilers['Recent orders (top 20)']));
        $group->spawnWithKey('topProducts', fn() => $this->fetchRows(self::QUERIES['Top products by revenue (top 10)'],  $profilers['Top products by revenue (top 10)']));

        /** @var array{users: array, products: array, orders: array, topProducts: array} $results */
        $results = $group->all()->await();
        return $results;
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

    private function renderResponse(array $results, array $queryProfiles, float $totalMs, string $responseType): ResponseInterface
    {
        if ($responseType === 'html' && $this->template !== null) {
            $html = $this->template->render('postgres::pdo', [
                'users'       => $results['users'],
                'products'    => $results['products'],
                'orders'      => $results['orders'],
                'topProducts' => $results['topProducts'],
                'profiles'    => $queryProfiles,
                'totalMs'     => $totalMs,
            ]);

            return new Response\HtmlResponse($html);
        }

        return new Response\JsonResponse([
            'users'                   => $results['users'],
            'products'                => $results['products'],
            'recent_orders'           => $results['orders'],
            'top_products_by_revenue' => $results['topProducts'],
            'profiles'                => $queryProfiles,
            'total_ms'                => $totalMs,
        ]);
    }

    private function fetchRows(string $sql, Profiler $profiler): array
    {
        $profiler->profilerStart($sql);
        $result = $this->dbAdapter->query($sql, AdapterInterface::QUERY_MODE_EXECUTE);
        $rows   = [];
        foreach ($result as $row) {
            $rows[] = (array) $row;
        }
        $profiler->profilerFinish();

        return $rows;
    }
}
