<?php

declare(strict_types=1);

/**
 * Lightweight async HTTP benchmark client (no external wrk/ab needed).
 *
 * Usage:
 *   php benchmarks/http_bench.php --host=127.0.0.1 --port=8610 --concurrency=100 --duration=10 --keepalive=1
 */

$opts = getopt('', ['host::', 'port::', 'concurrency::', 'duration::', 'keepalive::', 'warmup::']);

$host = (string) ($opts['host'] ?? '127.0.0.1');
$port = (int) ($opts['port'] ?? 8610);
$concurrency = max(1, (int) ($opts['concurrency'] ?? 100));
$duration = max(1, (int) ($opts['duration'] ?? 10));
$keepAlive = ((string) ($opts['keepalive'] ?? '1')) !== '0';
$warmup = max(0, (int) ($opts['warmup'] ?? 2));

$connHeader = $keepAlive ? 'keep-alive' : 'close';
$requestPayload = "GET / HTTP/1.1\r\nHost: {$host}:{$port}\r\nConnection: {$connHeader}\r\n\r\n";

$runPhase = static function (int $seconds, bool $recordLatencies) use ($host, $port, $concurrency, $keepAlive, $requestPayload): array {
    $sockets = [];
    $buffers = [];
    $sentAt = [];
    $latencies = [];
    $completed = 0;
    $errors = 0;

    $openConn = static function (int $idx) use ($host, $port, $requestPayload, &$sockets, &$buffers, &$sentAt, &$errors): void {
        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 2, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if ($sock === false) {
            $errors++;
            return;
        }
        stream_set_blocking($sock, false);
        $sockets[$idx] = $sock;
        $buffers[$idx] = '';
        $sentAt[$idx] = microtime(true);
        @fwrite($sock, $requestPayload);
    };

    for ($i = 0; $i < $concurrency; $i++) {
        $openConn($i);
    }

    $start = microtime(true);
    $deadline = $start + $seconds;

    while (microtime(true) < $deadline) {
        if ($sockets === []) {
            break;
        }
        $read = $sockets;
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, 50_000);
        if ($ready === false || $ready === 0) {
            continue;
        }

        foreach ($read as $idx => $sock) {
            $chunk = @fread($sock, 8192);
            if ($chunk === '' || $chunk === false) {
                if (feof($sock)) {
                    fclose($sock);
                    unset($sockets[$idx], $buffers[$idx], $sentAt[$idx]);
                    if (microtime(true) < $deadline) {
                        $openConn($idx);
                    }
                }
                continue;
            }

            $buffers[$idx] .= $chunk;
            while (str_contains($buffers[$idx], 'Hello World!')) {
                $pos = strpos($buffers[$idx], 'Hello World!');
                $buffers[$idx] = substr($buffers[$idx], $pos + 12);
                $completed++;

                if ($recordLatencies && isset($sentAt[$idx])) {
                    $latencies[] = (microtime(true) - $sentAt[$idx]) * 1000.0;
                }

                if (microtime(true) >= $deadline) {
                    break;
                }

                if ($keepAlive) {
                    $sentAt[$idx] = microtime(true);
                    @fwrite($sock, $requestPayload);
                } else {
                    fclose($sock);
                    unset($sockets[$idx], $buffers[$idx], $sentAt[$idx]);
                    $openConn($idx);
                    break;
                }
            }
        }
    }

    $elapsed = max(0.001, microtime(true) - $start);

    foreach ($sockets as $sock) {
        @fclose($sock);
    }

    return [
        'completed' => $completed,
        'errors' => $errors,
        'elapsed' => $elapsed,
        'qps' => $completed / $elapsed,
        'latencies' => $latencies,
    ];
};

if ($warmup > 0) {
    $runPhase($warmup, false);
}

$result = $runPhase($duration, true);
$latencies = $result['latencies'];
sort($latencies);

$percentile = static function (array $sorted, float $p): float {
    $count = count($sorted);
    if ($count === 0) {
        return 0.0;
    }
    $idx = (int) floor(($count - 1) * $p);
    return $sorted[$idx];
};

echo json_encode([
    'mode' => $keepAlive ? 'keep-alive' : 'short-conn',
    'concurrency' => $concurrency,
    'duration_s' => round($result['elapsed'], 2),
    'requests' => $result['completed'],
    'errors' => $result['errors'],
    'qps' => round($result['qps'], 1),
    'p50_ms' => round($percentile($latencies, 0.50), 2),
    'p95_ms' => round($percentile($latencies, 0.95), 2),
    'p99_ms' => round($percentile($latencies, 0.99), 2),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
