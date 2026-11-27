<?php

declare(strict_types=1);

/**
 * Unified HTTP benchmark server.
 *
 * Env:
 *   LOOP=poll|event|select   (default: poll)
 *   PORT=8610                (default: 8610)
 *   WORKERS=4                (default: 4)
 *
 * Usage:
 *   LOOP=poll   php benchmarks/http_server.php start -d
 *   LOOP=event  php benchmarks/http_server.php start -d
 *   LOOP=select php benchmarks/http_server.php start -d
 */

require_once __DIR__ . '/bootstrap.php';

use Workerman\Connection\TcpConnection;
use Workerman\Events\Event;
use Workerman\Events\Poll;
use Workerman\Events\Select;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

$loopName = strtolower((string) (getenv('LOOP') ?: 'poll'));
$port = (int) (getenv('PORT') ?: 8610);
$workers = (int) (getenv('WORKERS') ?: 4);

$loopMap = [
    'poll' => Poll::class,
    'event' => Event::class,
    'select' => Select::class,
];

if (!isset($loopMap[$loopName])) {
    fwrite(STDERR, "Unsupported LOOP={$loopName}. Use poll, event, or select.\n");
    exit(1);
}

Worker::$pidFile = "/tmp/bench-http-{$loopName}-{$port}.pid";
Worker::$logFile = "/tmp/bench-http-{$loopName}-{$port}.log";

$worker = new Worker("http://0.0.0.0:{$port}");
$worker->name = "bench-http-{$loopName}";
$worker->count = $workers;
$worker->eventLoop = $loopMap[$loopName];

$worker->onMessage = static function (TcpConnection $connection, Request $request): void {
    $connection->send('Hello World!');
};

Worker::runAll();
