<?php

declare(strict_types=1);

/**
 * HTTP server example using Workerman\Events\Poll (PHP 8.6+ native Io\Poll).
 *
 * Usage:
 *   php examples/http.php start
 *   php examples/http.php start -d
 *   php examples/http.php status|stop|restart
 *
 * Test:
 *   curl http://127.0.0.1:2345/
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Events\Poll;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

$worker = new Worker('http://0.0.0.0:2345');
$worker->name = 'poll-http';
$worker->count = 4;
$worker->eventLoop = Poll::class;

$worker->onMessage = static function (TcpConnection $connection, Request $request): void {
    $payload = json_encode([
        'message' => 'Hello World!',
        'driver' => Poll::class,
        'php' => PHP_VERSION,
        'method' => $request->method(),
        'path' => $request->path(),
        'query' => $request->get(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $connection->send(new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], $payload));
};

Worker::runAll();
