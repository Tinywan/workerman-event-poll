<?php

declare(strict_types=1);

namespace Workerman\Events;

use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\FailedPollWaitException;
use Io\Poll\Watcher;

/**
 * Workerman 5.x event loop driver based on the Io\Poll API.
 *
 * On PHP 8.6+ it uses the native engine-level implementation
 * (epoll on Linux, kqueue on macOS/BSD, WSAPoll on Windows).
 * On PHP 8.1+ it works through symfony/polyfill-io-poll.
 *
 * Usage:
 *     Worker::$eventLoopClass = \Workerman\Events\Poll::class;
 */
final class Poll implements EventInterface
{
    /**
     * @var array<int, Watcher>
     */
    private array $watchers = [];

    /**
     * @var array<int, array{read: ?callable, write: ?callable}>
     */
    private array $callbacks = [];

    /**
     * Timer id => [runAt, interval, func, args].
     *
     * @var array<int, array{float, float, callable, array}>
     */
    private array $timers = [];

    private static int $timerId = 1;

    /**
     * @var array<int, callable>
     */
    private array $signals = [];

    /**
     * @var ?callable
     */
    private $errorHandler = null;

    private bool $running = false;

    private ?bool $waitUsesDuration = null;

    public function __construct(
        private readonly Context $poll = new Context(),
    ) {
        if (DIRECTORY_SEPARATOR === '/' && extension_loaded('pcntl')) {
            pcntl_async_signals(true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = self::$timerId++;
        $this->timers[$timerId] = [microtime(true) + $delay, 0, $func, $args];
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay(int $timerId): bool
    {
        if (isset($this->timers[$timerId])) {
            unset($this->timers[$timerId]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $timerId = self::$timerId++;
        $this->timers[$timerId] = [microtime(true) + $interval, $interval, $func, $args];
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $fdKey = (int)$stream;
        $this->callbacks[$fdKey]['read'] = $func;
        $this->callbacks[$fdKey]['write'] ??= null;
        $this->updateWatcher($fdKey, $stream);
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fdKey = (int)$stream;
        if (!isset($this->callbacks[$fdKey]['read'])) {
            return false;
        }
        $this->callbacks[$fdKey]['read'] = null;
        $this->updateWatcher($fdKey, $stream);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func): void
    {
        $fdKey = (int)$stream;
        $this->callbacks[$fdKey]['write'] = $func;
        $this->callbacks[$fdKey]['read'] ??= null;
        $this->updateWatcher($fdKey, $stream);
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fdKey = (int)$stream;
        if (!isset($this->callbacks[$fdKey]['write'])) {
            return false;
        }
        $this->callbacks[$fdKey]['write'] = null;
        $this->updateWatcher($fdKey, $stream);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !extension_loaded('pcntl')) {
            throw new \RuntimeException('Signals are only supported on POSIX systems with the pcntl extension');
        }
        $this->signals[$signal] = $func;
        pcntl_signal($signal, fn () => $this->safeCall($func, [$signal]));
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        if (!isset($this->signals[$signal])) {
            return false;
        }
        pcntl_signal($signal, SIG_DFL);
        unset($this->signals[$signal]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer(): void
    {
        $this->timers = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $this->running = true;
        while ($this->running) {
            if (!$this->watchers && !$this->timers) {
                usleep(10000);
                continue;
            }

            try {
                $triggered = $this->wait($this->nextTimerTimeoutMicroseconds());
            } catch (FailedPollWaitException) {
                $triggered = [];
            }

            foreach ($triggered as $watcher) {
                $this->dispatch($watcher);
            }

            $this->runTimers();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount(): int
    {
        return count($this->timers);
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * Add, modify or remove the watcher of a stream to match its current callbacks.
     *
     * @param resource $stream
     */
    private function updateWatcher(int $fdKey, $stream): void
    {
        $events = [];
        if (isset($this->callbacks[$fdKey]['read'])) {
            $events[] = Event::Read;
        }
        if (isset($this->callbacks[$fdKey]['write'])) {
            $events[] = Event::Write;
        }

        if (!$events) {
            unset($this->callbacks[$fdKey]);
            if (isset($this->watchers[$fdKey])) {
                $this->watchers[$fdKey]->remove();
                unset($this->watchers[$fdKey]);
            }
            return;
        }

        if (isset($this->watchers[$fdKey])) {
            $this->watchers[$fdKey]->modifyEvents($events);
            return;
        }

        $this->watchers[$fdKey] = $this->poll->add(new \StreamPollHandle($stream), $events);
    }

    private function dispatch(Watcher $watcher): void
    {
        $stream = $watcher->getHandle()->getStream();
        if (!is_resource($stream)) {
            return;
        }
        $fdKey = (int)$stream;
        $callbacks = $this->callbacks[$fdKey] ?? [];

        if ($watcher->hasTriggered(Event::HangUp) || $watcher->hasTriggered(Event::Error)) {
            if (isset($callbacks['read'])) {
                $this->safeCall($callbacks['read'], [$stream]);
            }
            return;
        }

        if ($watcher->hasTriggered(Event::Read) && isset($callbacks['read'])) {
            $this->safeCall($callbacks['read'], [$stream]);
        }

        if ($watcher->hasTriggered(Event::Write) && isset($callbacks['write'])) {
            $this->safeCall($callbacks['write'], [$stream]);
        }
    }

    private function runTimers(): void
    {
        $now = microtime(true);
        foreach ($this->timers as $timerId => [$runAt, $interval, $func, $args]) {
            if ($runAt > $now) {
                continue;
            }
            if ($interval > 0) {
                $this->timers[$timerId][0] = $now + $interval;
            } else {
                unset($this->timers[$timerId]);
            }
            $this->safeCall($func, $args);
        }
    }

    /**
     * Dispatch to the correct Context::wait() signature.
     *
     * Native PHP 8.6 and polyfill 1.x-dev take ?Time\Duration, while
     * released polyfill 1.41 takes (?int $seconds, int $microseconds).
     *
     * @param int|null $timeoutMicroseconds null means block indefinitely
     * @return array<Watcher>
     */
    private function wait(?int $timeoutMicroseconds): array
    {
        $this->waitUsesDuration ??= (string)(new \ReflectionMethod($this->poll, 'wait'))
            ->getParameters()[0]->getType() === '?Time\Duration';

        if ($this->waitUsesDuration) {
            $timeout = $timeoutMicroseconds === null
                ? null
                : \Time\Duration::fromMicroseconds($timeoutMicroseconds);
            return $this->poll->wait($timeout);
        }

        return $this->poll->wait(
            $timeoutMicroseconds === null ? null : intdiv($timeoutMicroseconds, 1_000_000),
            $timeoutMicroseconds === null ? 0 : $timeoutMicroseconds % 1_000_000,
        );
    }

    /**
     * @return int|null microseconds until the next timer fires, null means block indefinitely
     */
    private function nextTimerTimeoutMicroseconds(): ?int
    {
        if (!$this->timers) {
            return null;
        }
        $now = microtime(true);
        $next = min(array_column($this->timers, 0));
        return max(0, (int)(($next - $now) * 1_000_000));
    }

    private function safeCall(callable $func, array $args = []): void
    {
        try {
            $func(...$args);
        } catch (\Throwable $e) {
            if ($this->errorHandler === null) {
                echo $e;
            } else {
                ($this->errorHandler)($e);
            }
        }
    }
}
