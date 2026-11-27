<?php

declare(strict_types=1);

use Workerman\Events\Poll;

it('reports native Io\\Poll support and backend name on PHP 8.6+', function (): void {
    expect(Poll::isSupported())->toBeTrue();

    $loop = new Poll();
    $backend = $loop->getBackendName();

    expect($backend)->toBeString();
    expect($backend === '')->toBeFalse();
});

it('handles stream readable and writable events', function (): void {
    $loop = new Poll();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false) {
        throw new RuntimeException('Failed to create stream socket pair.');
    }

    [$reader, $writer] = $pair;
    stream_set_blocking($reader, false);
    stream_set_blocking($writer, false);

    $written = false;
    $received = '';

    $loop->onWritable($writer, static function ($stream) use ($loop, &$written): void {
        fwrite($stream, 'hello-poll');
        $written = true;
        $loop->offWritable($stream);
    });

    $loop->onReadable($reader, static function ($stream) use ($loop, &$received): void {
        $received = (string) fread($stream, 1024);
        $loop->offReadable($stream);
        $loop->stop();
    });

    $loop->delay(1.0, $loop->stop(...));
    $loop->run();

    fclose($reader);
    fclose($writer);

    expect($written)->toBeTrue();
    expect($received)->toBe('hello-poll');
});

it('executes delay timers and supports offDelay cancellation', function (): void {
    $loop = new Poll();
    $fired = [];

    $loop->delay(
        0.02,
        static function (string $label) use (&$fired): void {
            $fired[] = $label;
        },
        ['first'],
    );

    $cancelledId = $loop->delay(0.01, static function () use (&$fired): void {
        $fired[] = 'cancelled';
    });

    expect($loop->offDelay($cancelledId))->toBeTrue();
    expect($loop->offDelay($cancelledId))->toBeFalse();
    expect($loop->getTimerCount())->toBe(1);

    $loop->delay(0.08, $loop->stop(...));
    $loop->run();

    expect($fired)->toBe(['first']);
    expect($loop->getTimerCount())->toBe(0);
});

it('executes repeat timers and ignores invalid intervals', function (): void {
    $loop = new Poll();
    $count = 0;

    expect($loop->repeat(0.0, static function (): void {}))->toBe(0);
    expect($loop->repeat(-1.0, static function (): void {}))->toBe(0);

    $timerId = 0;
    $timerId = $loop->repeat(0.02, static function () use ($loop, &$count, &$timerId): void {
        $count++;
        if ($count >= 3) {
            $loop->offRepeat($timerId);
            $loop->stop();
        }
    });

    expect($timerId)->toBeGreaterThan(0);

    $loop->delay(1.0, $loop->stop(...));
    $loop->run();

    expect($count)->toBe(3);
    expect($loop->getTimerCount())->toBe(0);
});

it('deletes all timers cleanly', function (): void {
    $loop = new Poll();
    $fired = false;

    $loop->delay(0.05, static function () use (&$fired): void {
        $fired = true;
    });
    $loop->repeat(0.05, static function () use (&$fired): void {
        $fired = true;
    });

    expect($loop->getTimerCount())->toBe(2);

    $loop->deleteAllTimer();

    expect($loop->getTimerCount())->toBe(0);

    $loop->delay(0.08, $loop->stop(...));
    $loop->run();

    expect($fired)->toBeFalse();
});

it('forwards uncaught callback exceptions to custom error handler', function (): void {
    $loop = new Poll();
    $captured = null;

    $loop->setErrorHandler(static function (Throwable $e) use ($loop, &$captured): void {
        $captured = $e->getMessage();
        $loop->stop();
    });

    $loop->delay(0.01, static function (): void {
        throw new RuntimeException('boom-from-timer');
    });

    $loop->delay(1.0, $loop->stop(...));
    $loop->run();

    expect($captured)->toBe('boom-from-timer');
});

it('interrupts wait on POSIX signal', function (): void {
    if (DIRECTORY_SEPARATOR !== '/' || !function_exists('pcntl_signal') || !function_exists('posix_kill')) {
        $this->markTestSkipped('POSIX signals require ext-pcntl and ext-posix.');
    }

    $loop = new Poll();
    $receivedSignal = null;

    $loop->onSignal(SIGUSR1, static function (int $signal) use ($loop, &$receivedSignal): void {
        $receivedSignal = $signal;
        $loop->offSignal(SIGUSR1);
        $loop->stop();
    });

    $loop->delay(0.05, static fn() => posix_kill(posix_getpid(), SIGUSR1));
    $loop->delay(2.0, $loop->stop(...));

    $start = microtime(true);
    $loop->run();
    $elapsed = microtime(true) - $start;

    expect($receivedSignal)->toBe(SIGUSR1);
    expect($elapsed)->toBeLessThan(1.0);
});
