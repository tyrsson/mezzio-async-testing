<?php

declare(strict_types=1);

namespace PhpDb\Async\Profiler;

use PhpDb\Adapter\Profiler\Profiler as BaseProfiler;
use PhpDb\Adapter\Profiler\ProfilerInterface;
use PhpDb\Adapter\StatementContainerInterface;

use function hrtime;
use function is_string;
use function microtime;

/**
 * hrtime-based profiler for async query workloads.
 *
 * Extends the base PhpDb profiler, swapping microtime() for hrtime(true)
 * so that elapsed measurements are not skewed by system clock adjustments.
 * Each profile entry gains two extra fields:
 *   - wall_start (float)  microtime(true) at query start, for human-readable display
 *   - elapsed_ms (float)  wall time in milliseconds derived from the hrtime diff
 *
 * One instance should be created per concurrent query and passed into the
 * execution context (e.g. fetchRows()), then collected after await_all_or_fail().
 * Instances are not shared across coroutines.
 *
 * @phpstan-import-type ProfileShape from BaseProfiler
 * @phpstan-type AsyncProfileShape array{
 *     sql:        string,
 *     parameters: \PhpDb\Adapter\ParameterContainer|null,
 *     start:      int,
 *     end:        int|null,
 *     elapse:     float|null,
 *     elapsed_ms: float|null,
 *     wall_start: float,
 * }
 */
final class Profiler extends BaseProfiler
{
    /**
     * @return $this
     */
    public function profilerStart(string|StatementContainerInterface $target): ProfilerInterface
    {
        $sql = is_string($target) ? $target : $target->getSql();

        $parameters = null;
        if (! is_string($target)) {
            $container = $target->getParameterContainer();
            if ($container !== null) {
                $parameters = clone $container;
            }
        }

        $this->profiles[$this->currentIndex] = [
            'sql'        => $sql,
            'parameters' => $parameters,
            'start'      => hrtime(true),
            'end'        => null,
            'elapse'     => null,
            'elapsed_ms' => null,
            'wall_start' => microtime(true),
        ];

        return $this;
    }

    /**
     * @return $this
     */
    public function profilerFinish(): ProfilerInterface
    {
        $current = &$this->profiles[$this->currentIndex];

        $endNs             = hrtime(true);
        $current['end']    = $endNs;
        $current['elapse'] = ($endNs - $current['start']) / 1_000_000_000;
        $current['elapsed_ms'] = ($endNs - $current['start']) / 1_000_000;

        $this->currentIndex++;

        return $this;
    }
}
