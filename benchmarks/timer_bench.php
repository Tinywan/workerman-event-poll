<?php

declare(strict_types=1);

/**
 * Timer micro-benchmark: Poll (PHP SplPriorityQueue) vs Event (libevent C heap) vs Select.
 *
 * Usage:
 *   php benchmarks/timer_bench.php poll 10000
 *   php benchmarks/timer_bench.php event 10000
 *   php benchmarks/timer_bench.php select 10000
 */

require_once __DIR__ . '/bootstrap.php';

use Workerman\Events\Event;
use Workerman\Events\EventInterface;
use Workerman\Events\Poll;
use Workerman\Events\Select;

$driver = strtolower((string) ($argv[1] ?? 'poll'));
$count = max(1, (int) ($argv[2] ?? 10_000));

/** @var EventInterface $loop */
$loop = match ($driver) {
    'poll' => new Poll(),
    'event' => new Event(),
    'select' => new Select(),
    default => throw new InvalidArgumentException("Unknown driver: {$driver}"),
};

$fired = 0;
$memBefore = memory_get_usage(true);
$t0 = microtime(true);

for ($i = 0; $i < $count; $i++) {
    // Spread across 10ms..110ms
    $delay = 0.01 + (($i % 100) * 0.001);
    $loop->delay($delay, static function () use (&$fired, $count, $loop): void {
        $fired++;
        if ($fired >= $count) {
            $loop->stop();
        }
    });
}

$registerMs = (microtime(true) - $t0) * 1000.0;
$memAfterRegister = memory_get_usage(true);

$tRun = microtime(true);
$loop->run();
$runMs = (microtime(true) - $tRun) * 1000.0;
$peakMb = memory_get_peak_usage(true) / 1024 / 1024;

echo json_encode([
    'php' => PHP_VERSION,
    'driver' => $driver,
    'timers' => $count,
    'fired' => $fired,
    'register_ms' => round($registerMs, 2),
    'fire_total_ms' => round($runMs, 2),
    'delta_mem_mb' => round(($memAfterRegister - $memBefore) / 1024 / 1024, 2),
    'peak_mem_mb' => round($peakMb, 2),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
