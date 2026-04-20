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

namespace App\Handler;

use Laminas\Diactoros\Response;
use Mezzio\Template\TemplateRendererInterface;
use PhpDb\Async\Adapter;
use PhpDb\Async\Profiler\Profiler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

use PhpDb\Adapter\AdapterInterface;

use function array_keys;
use function array_values;
use function Async\await_all_or_fail;
use function Async\spawn;
use function count;
use function file_get_contents;
use function hrtime;
use function json_decode;
use function round;

use const JSON_THROW_ON_ERROR;

final class PostgresHandler implements RequestHandlerInterface
{
    private const SEED_FILE = __DIR__ . '/../../../../data/postgres/seed.json';

    // -------------------------------------------------------------------------
    // DDL — PostgreSQL-specific; BIGSERIAL provides auto-increment PK
    // -------------------------------------------------------------------------

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
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->dbAdapter instanceof Adapter) {
            return new Response\JsonResponse(['error' => 'No database adapter configured'], 503);
        }

        $action = $request->getQueryParams()['action'] ?? 'query';

        return match ($action) {
            'setup'    => $this->setup(),
            'teardown' => $this->teardown(),
            default    => $this->query(),
        };
    }

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    private function setup(): ResponseInterface
    {
        // Create tables in FK-dependency order
        foreach ([self::DDL_USERS, self::DDL_PRODUCTS, self::DDL_ORDERS, self::DDL_ORDER_ITEMS] as $ddl) {
            $this->dbAdapter->query($ddl, AdapterInterface::QUERY_MODE_EXECUTE);
        }

        $seed   = $this->loadSeed();
        $counts = $this->seed($seed);

        return new Response\JsonResponse(['tables_created' => true, 'seeded' => $counts]);
    }

    private function teardown(): ResponseInterface
    {
        // Drop in reverse FK-dependency order
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
        // Reset all data and restart sequences for deterministic IDs
        $this->dbAdapter->query(
            'TRUNCATE bm_order_items, bm_orders, bm_products, bm_users RESTART IDENTITY CASCADE',
            AdapterInterface::QUERY_MODE_EXECUTE
        );

        // Use QUERY_MODE_EXECUTE with platform quoting to avoid the shallow-clone
        // ParameterContainer accumulation bug in PhpDb\Pgsql\Statement (no __clone()).
        // TableGateway::insert() → Sql::prepareStatementForSqlObject() →
        // Driver::createStatement() shallow-clones the prototype, sharing the PC;
        // switching tables causes cross-table key accumulation → wrong param count.
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

    // -------------------------------------------------------------------------
    // Concurrent read queries — the benchmark workload
    //
    // Four independent SELECT queries are spawned as separate TrueAsync
    // coroutines and awaited together.  When each query yields on PDO I/O the
    // scheduler can service other coroutines (including other HTTP requests),
    // so the four queries overlap in time rather than running sequentially.
    //
    // NOTE: For maximum throughput in production replace the single shared
    //       AdapterInterface with an Async\Pool of connections so that each
    //       coroutine holds its own dedicated socket to PostgreSQL.
    // -------------------------------------------------------------------------

    private function query(): ResponseInterface
    {
        $startNs = hrtime(true);

        $profilers = [
            'Users (top 20 by id)'           => new Profiler(),
            'Products (top 20 by price)'      => new Profiler(),
            'Recent orders (top 20)'          => new Profiler(),
            'Top products by revenue (top 10)' => new Profiler(),
        ];

        [$label0, $label1, $label2, $label3] = array_keys($profilers);
        [$prof0,  $prof1,  $prof2,  $prof3]  = array_values($profilers);

        try {
            $usersCoroutine    = spawn(fn() => $this->fetchRows(
                'SELECT id, username, email, created_at
                   FROM bm_users
                  ORDER BY id
                  LIMIT 20',
                $prof0
            ));

            $productsCoroutine = spawn(fn() => $this->fetchRows(
                'SELECT id, name, price, stock
                   FROM bm_products
                  ORDER BY price DESC
                  LIMIT 20',
                $prof1
            ));

            $ordersCoroutine   = spawn(fn() => $this->fetchRows(
                'SELECT o.id, o.total, o.status, o.created_at, u.username
                   FROM bm_orders o
                   JOIN bm_users u ON u.id = o.user_id
                  ORDER BY o.created_at DESC
                  LIMIT 20',
                $prof2
            ));

            $revenueCoroutine  = spawn(fn() => $this->fetchRows(
                'SELECT p.name,
                        SUM(oi.quantity * oi.unit_price) AS revenue,
                        SUM(oi.quantity) AS units_sold
                   FROM bm_order_items oi
                   JOIN bm_products p ON p.id = oi.product_id
                  GROUP BY p.id, p.name
                  ORDER BY revenue DESC
                  LIMIT 10',
                $prof3
            ));

            [$users, $products, $orders, $topProducts] = await_all_or_fail([
                $usersCoroutine,
                $productsCoroutine,
                $ordersCoroutine,
                $revenueCoroutine,
            ]);

            $totalMs = round((hrtime(true) - $startNs) / 1_000_000, 2);

            // Collect labelled profile entries for the template
            $queryProfiles = [];
            foreach ($profilers as $label => $profiler) {
                $profile = $profiler->getLastProfile();
                $queryProfiles[] = [
                    'label'      => $label,
                    'sql'        => $profile['sql'] ?? '',
                    'elapsed_ms' => $profile['elapsed_ms'] ?? null,
                    'wall_start' => $profile['wall_start'] ?? null,
                ];
            }

            if ($this->template !== null) {
                $html = $this->template->render('app::postgres', [
                    'users'       => $users,
                    'products'    => $products,
                    'orders'      => $orders,
                    'topProducts' => $topProducts,
                    'profiles'    => $queryProfiles,
                    'totalMs'     => $totalMs,
                ]);

                return new Response\HtmlResponse($html);
            }

            // Fallback when no template renderer is wired
            return new Response\JsonResponse([
                'users'                   => $users,
                'products'                => $products,
                'recent_orders'           => $orders,
                'top_products_by_revenue' => $topProducts,
                'profiles'                => $queryProfiles,
                'total_ms'                => $totalMs,
            ]);
        } catch (Throwable $e) {
            return new Response\JsonResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
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
