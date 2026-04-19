<?php

declare(strict_types=1);

namespace Mezzio\Async\Log;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function is_dir;
use function mkdir;

/**
 * Adds local file + stderr handlers to the Monolog logger when running
 * under the async CLI server. Without handlers the axleus-log LogFactory
 * produces a silent logger; this delegator ensures output is visible.
 */
final class LoggerDelegator
{
    public function __invoke(
        ContainerInterface $container,
        string $name,
        callable $callback,
    ): LoggerInterface {
        /** @var Logger $logger */
        $logger = $callback();

        $config      = $container->has('config') ? $container->get('config') : [];
        $asyncConfig = $config['mezzio-async'] ?? [];
        $logDir      = $asyncConfig['log_dir'] ?? 'data/psr/log';

        if (! is_dir($logDir)) {
            mkdir($logDir, 0777, recursive: true);
        }

        // Write to a rolling daily file — useLocking: false prevents buffering
        $logger->pushHandler(new StreamHandler(
            stream: $logDir . '/async.log',
            level: Level::Debug,
            bubble: true,
            filePermission: 0666,
            useLocking: false,
        ));

        // Also write to stderr so output appears in `docker logs`
        $logger->pushHandler(new StreamHandler(
            stream: 'php://stderr',
            level: Level::Debug,
            bubble: true,
            filePermission: null,
            useLocking: false,
        ));

        return $logger;
    }
}
