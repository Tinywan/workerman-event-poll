<?php

declare(strict_types=1);

/**
 * Single-worker TCP echo server for C10K benchmark.
 *
 * Env:
 *   LOOP=poll|event|select   (default: poll)
 *   PORT=8621                (default: 8621)
 */

require_once __DIR__ . '/bootstrap.php';

use Workerman\Connection\TcpConnection;
use Workerman\Events\Event;
use Workerman\Events\Poll;
use Workerman\Events\Select;
use Workerman\Worker;

$loopName = strtolower((string) (getenv('LOOP') ?: 'poll'));
$port = (int) (getenv('PORT') ?: 8621);

$loopMap = [
    'poll' => Poll::class,
    'event' => Event::class,
    'select' => Select::class,
];

if (!isset($loopMap[$loopName])) {
    fwrite(STDERR, "Unsupported LOOP={$loopName}.\n");
    exit(1);
}

Worker::$pidFile = "/tmp/bench-c10k-{$loopName}-{$port}.pid";
Worker::$logFile = "/tmp/bench-c10k-{$loopName}-{$port}.log";

$worker = new Worker("tcp://0.0.0.0:{$port}");
$worker->name = "bench-c10k-{$loopName}";
$worker->count = 1;
$worker->eventLoop = $loopMap[$loopName];

$worker->onMessage = static function (TcpConnection $connection, string $data): void {
    $connection->send('pong' . "\n");
};

Worker::runAll();
