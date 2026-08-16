<?php
/**
 * TCP + HTTP demo server.
 *
 * Usage: php examples/server.php start        (debug mode)
 *        php examples/server.php start -d     (daemon)
 *        php examples/server.php stop|status|restart
 */

require __DIR__ . '/../vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;
use Tinywan\WorkermanEventPoll\Poll;

Worker::$eventLoopClass = Poll::class;

// TCP worker: raw echo on 2345
$tcp = new Worker('tcp://0.0.0.0:2345');
$tcp->name = 'poll-tcp';
$tcp->onMessage = function (TcpConnection $connection, string $data) {
    $connection->send("tcp-echo: {$data}");
};

// HTTP worker: JSON info on 8080
$http = new Worker('http://0.0.0.0:8080');
$http->name = 'poll-http';
$http->onMessage = function (TcpConnection $connection, Request $request) {
    $connection->send(new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'driver' => 'Tinywan\\WorkermanEventPoll\\Poll',
        'php' => PHP_VERSION,
        'method' => $request->method(),
        'path' => $request->path(),
        'query' => $request->get(),
        'post' => $request->post(),
    ])));
};

$http->onWorkerStart = function () {
    Timer::add(1, fn () => print('[timer] tick' . PHP_EOL));
};

Worker::runAll();
