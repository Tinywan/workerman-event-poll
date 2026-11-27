<?php

declare(strict_types=1);

/**
 * WebSocket server example using Workerman\Events\Poll (PHP 8.6+ native Io\Poll).
 *
 * Usage:
 *   php examples/websocket.php start
 *   php examples/websocket.php start -d
 *   php examples/websocket.php status|stop|restart
 *
 * Test (browser console):
 *   const ws = new WebSocket('ws://127.0.0.1:8602');
 *   ws.onmessage = (e) => console.log(e.data);
 *   ws.onopen = () => ws.send('world');
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Events\Poll;
use Workerman\Worker;

$worker = new Worker('websocket://0.0.0.0:8602');
$worker->name = 'poll-websocket';
$worker->count = 4;
$worker->eventLoop = Poll::class;

$worker->onConnect = static function (TcpConnection $connection): void {
    $connection->send('connected via ' . Poll::class);
};

$worker->onMessage = static function (TcpConnection $connection, string $data): void {
    $connection->send('hello ' . $data);
};

Worker::runAll();
