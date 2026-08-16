<?php
/**
 * Event loop self-test: stream read/write, delay and repeat timers.
 *
 * Usage: composer install && php examples/loop-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Workerman\Events\Poll;

$loop = new Poll();

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0)
    ?: stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
[$a, $b] = $pair;
stream_set_blocking($a, false);
stream_set_blocking($b, false);

$results = [];

$loop->onReadable($a, function ($stream) use ($loop, &$results) {
    $results['read'] = fread($stream, 1024);
    $loop->offReadable($stream);
});

$loop->delay(0.05, fn () => fwrite($b, 'hello-poll'));

$loop->delay(0.1, function () use (&$results) {
    $results['delay'] = true;
});

$count = 0;
$timerId = $loop->repeat(0.02, function () use ($loop, &$count, &$results, &$timerId) {
    if (++$count >= 3) {
        $results['repeat'] = $count;
        $loop->offRepeat($timerId);
    }
});

$loop->delay(0.5, function () use ($loop, &$results) {
    $results['done'] = true;
    $loop->stop();
});

$start = microtime(true);
$loop->run();
$elapsed = round(microtime(true) - $start, 2);

$failures = [];
($results['read'] ?? null) === 'hello-poll' || $failures[] = 'read: got ' . var_export($results['read'] ?? null, true);
($results['delay'] ?? false) === true || $failures[] = 'delay timer not fired';
($results['repeat'] ?? 0) >= 3 || $failures[] = 'repeat timer fired ' . ($results['repeat'] ?? 0) . ' times';
($results['done'] ?? false) === true || $failures[] = 'stop timer not fired';
$loop->getTimerCount() === 0 || $failures[] = 'timer count is ' . $loop->getTimerCount();

if ($failures) {
    echo "FAILED:\n - ", implode("\n - ", $failures), "\n";
    exit(1);
}
echo "PHP ", PHP_VERSION, " - all assertions passed in {$elapsed}s\n";
