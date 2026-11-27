<?php

declare(strict_types=1);

/**
 * TCP server example using Workerman\Events\Poll (PHP 8.6+ native Io\Poll).
 *
 * Usage:
 *   php examples/tcp.php start
 *   php examples/tcp.php start -d
 *   php examples/tcp.php status|stop|restart
 *
 * Test:
 *   telnet 127.0.0.1 8603
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Events\Poll;
use Workerman\Worker;

$worker = new Worker('tcp://0.0.0.0:8603');
$worker->name = 'poll-tcp';
$worker->count = 4;
$worker->eventLoop = Poll::class;

$worker->onMessage = static function (TcpConnection $connection, string $data): void {
    $connection->send('hello ' . $data);
};

Worker::runAll();
