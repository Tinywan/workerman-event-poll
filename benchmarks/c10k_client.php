<?php

declare(strict_types=1);

/**
 * C10K async TCP benchmark client powered by PHP 8.6 native Io\Poll.
 *
 * Holds N concurrent TCP connections simultaneously, then runs M rounds of
 * ping/pong across K active connections while all N connections stay open.
 *
 * Usage (in PHP 8.6):
 *   php benchmarks/c10k_client.php --host=127.0.0.1 --port=8621 --connections=10000 --active=1000 --rounds=5
 */

use Io\Poll\Context;
use Io\Poll\Event;
use Time\Duration;

$opts = getopt('', ['host::', 'port::', 'connections::', 'active::', 'rounds::', 'batch::']);

$host = (string) ($opts['host'] ?? '127.0.0.1');
$port = (int) ($opts['port'] ?? 8621);
$targetConns = max(1, (int) ($opts['connections'] ?? 10_000));
$activeConns = min($targetConns, max(1, (int) ($opts['active'] ?? 1_000)));
$rounds = max(1, (int) ($opts['rounds'] ?? 5));
$batchSize = max(50, (int) ($opts['batch'] ?? 500));

$ctx = new Context();
$sockets = [];
$handles = [];
$watchers = [];
$connected = 0;
$connectErrors = 0;

$tConnectStart = microtime(true);

// Establish connections in batches to avoid overflowing listen backlog
for ($offset = 0; $offset < $targetConns; $offset += $batchSize) {
    $end = min($targetConns, $offset + $batchSize);
    $pending = 0;
    $batchWatchers = [];

    for ($i = $offset; $i < $end; $i++) {
        $sock = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
        );
        if ($sock === false) {
            $connectErrors++;
            continue;
        }
        stream_set_blocking($sock, false);
        $sockets[$i] = $sock;
        $handles[$i] = new StreamPollHandle($sock);
        $batchWatchers[$i] = $ctx->add($handles[$i], [Event::Write], $i);
        $pending++;
    }

    $batchDeadline = microtime(true) + 10.0;
    while ($pending > 0 && microtime(true) < $batchDeadline) {
        $ready = $ctx->wait(Duration::fromMilliseconds(200));
        foreach ($ready as $w) {
            $id = $w->getData();
            if (!is_int($id) || !isset($batchWatchers[$id])) {
                continue;
            }
            $w->remove();
            unset($batchWatchers[$id]);
            $pending--;

            if ($w->hasTriggered(Event::Error) || $w->hasTriggered(Event::HangUp)) {
                $connectErrors++;
                @fclose($sockets[$id]);
                unset($sockets[$id], $handles[$id]);
                continue;
            }

            $connected++;
        }
    }

    // Cleanup timed-out connections in this batch
    foreach ($batchWatchers as $id => $w) {
        $w->remove();
        $connectErrors++;
        if (isset($sockets[$id])) {
            @fclose($sockets[$id]);
            unset($sockets[$id], $handles[$id]);
        }
    }
}

$connectMs = (microtime(true) - $tConnectStart) * 1000.0;

// Pick active connections and run ping/pong rounds while ALL connected sockets stay open
$allIds = array_keys($sockets);
$activeIds = array_slice($allIds, 0, min($activeConns, count($allIds)));

foreach ($activeIds as $id) {
    $watchers[$id] = $ctx->add($handles[$id], [Event::Read], $id);
}

$sentAt = [];
$buffers = [];
$roundDone = [];
$latencies = [];
$messagesCompleted = 0;
$messageErrors = 0;
$expectedTotal = count($activeIds) * $rounds;

$tMsgStart = microtime(true);

// Kick off round 1
foreach ($activeIds as $id) {
    $buffers[$id] = '';
    $roundDone[$id] = 0;
    $sentAt[$id] = microtime(true);
    $written = @fwrite($sockets[$id], "ping\n");
    if ($written === false || $written === 0) {
        $messageErrors++;
    }
}

$msgDeadline = microtime(true) + 15.0;
while ($messagesCompleted + $messageErrors < $expectedTotal && microtime(true) < $msgDeadline) {
    $ready = $ctx->wait(Duration::fromMilliseconds(200));
    foreach ($ready as $w) {
        $id = $w->getData();
        if (!is_int($id) || !isset($sockets[$id])) {
            continue;
        }
        if ($w->hasTriggered(Event::Error) || $w->hasTriggered(Event::HangUp)) {
            $remaining = $rounds - ($roundDone[$id] ?? 0);
            $messageErrors += max(0, $remaining);
            $w->remove();
            unset($watchers[$id]);
            continue;
        }

        $chunk = @fread($sockets[$id], 1024);
        if ($chunk === '' || $chunk === false) {
            if (feof($sockets[$id])) {
                $remaining = $rounds - ($roundDone[$id] ?? 0);
                $messageErrors += max(0, $remaining);
                $w->remove();
                unset($watchers[$id]);
            }
            continue;
        }

        $buffers[$id] .= $chunk;
        while (str_contains($buffers[$id], "pong\n")) {
            $pos = strpos($buffers[$id], "pong\n");
            $buffers[$id] = substr($buffers[$id], $pos + 5);
            $latencies[] = (microtime(true) - $sentAt[$id]) * 1000.0;
            $messagesCompleted++;
            $roundDone[$id]++;

            if ($roundDone[$id] < $rounds) {
                $sentAt[$id] = microtime(true);
                @fwrite($sockets[$id], "ping\n");
            }
        }
    }
}

$msgMs = (microtime(true) - $tMsgStart) * 1000.0;

// Clean up watchers & sockets
foreach ($watchers as $w) {
    $w->remove();
}
foreach ($sockets as $sock) {
    @fclose($sock);
}

sort($latencies);
$pct = static function (array $arr, float $p): float {
    $n = count($arr);
    if ($n === 0) {
        return 0.0;
    }
    return $arr[(int) floor(($n - 1) * $p)];
};

echo json_encode([
    'target_conns' => $targetConns,
    'connected' => $connected,
    'connect_errors' => $connectErrors,
    'connect_ms' => round($connectMs, 1),
    'active_conns' => count($activeIds),
    'rounds' => $rounds,
    'messages_ok' => $messagesCompleted,
    'messages_err' => $messageErrors,
    'msg_total_ms' => round($msgMs, 1),
    'msg_qps' => $msgMs > 0 ? round($messagesCompleted / ($msgMs / 1000.0), 1) : 0.0,
    'p50_ms' => round($pct($latencies, 0.50), 2),
    'p95_ms' => round($pct($latencies, 0.95), 2),
    'p99_ms' => round($pct($latencies, 0.99), 2),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
