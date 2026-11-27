<?php

declare(strict_types=1);

namespace Workerman\Events;

use Io\Poll\Backend;
use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\FailedPollOperationException;
use Io\Poll\FailedPollWaitException;
use Io\Poll\Watcher;
use RuntimeException;
use SplPriorityQueue;
use StreamPollHandle;
use Throwable;
use Time\Duration;
use Workerman\Worker;

use function array_keys;
use function ceil;
use function class_exists;
use function count;
use function function_exists;
use function in_array;
use function is_int;
use function is_resource;
use function method_exists;
use function microtime;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function str_contains;
use function strtolower;

use const DIRECTORY_SEPARATOR;
use const PHP_VERSION_ID;
use const SIG_IGN;

/**
 * 基于 PHP 8.6+ 原生 Io\Poll 的 Workerman 5 事件循环驱动
 *
 * 特点：
 *  1. 零第三方扩展依赖：直接使用 PHP 8.6 内置 Io\Poll（Linux 自动选用 Epoll，macOS 自动选用 Kqueue），
 *     彻底突破 stream_select 的 1024 FD 上限；
 *  2. 完整实现 Workerman 5 的 EventInterface（I/O、延迟/周期定时器、POSIX 信号、错误处理）；
 *  3. 同一 stream 仅维护一个 Watcher，读写事件变更时通过 Watcher::modifyEvents() 原地更新；
 *  4. 信号注册时关闭 restart_syscalls（false），保证阻塞中的 Context::wait() 能被信号及时打断。
 */
final class Poll implements EventInterface
{
    private bool $running = true;

    private ?Context $context = null;

    private Backend $backend;

    /**
     * 定时器最小堆（priority 为运行时间戳的相反数，data 为 [timerId, runTime]）
     *
     * @var SplPriorityQueue<float, array{0: int, 1: float}>
     */
    private SplPriorityQueue $scheduler;

    /**
     * stream 事件表：fdKey => [fd, read, write, handle, watcher]
     *
     * @var array<int, array{
     *     fd: resource,
     *     read: ?callable,
     *     write: ?callable,
     *     handle: ?StreamPollHandle,
     *     watcher: ?Watcher
     * }>
     */
    private array $events = [];

    /**
     * 信号事件表：signal => callable
     *
     * @var array<int, callable>
     */
    private array $signalEvents = [];

    /**
     * 定时器事件表：timerId => [func, args, interval|null]
     *
     * @var array<int, array{0: callable, 1: array, 2: ?float}>
     */
    private array $eventTimer = [];

    private int $timerId = 1;

    private int $streamWatcherCount = 0;

    /**
     * @var ?callable(Throwable): void
     */
    private $errorHandler = null;

    /**
     * 当前 PHP 环境是否支持原生 Io\Poll 事件循环
     */
    public static function isSupported(): bool
    {
        return (
            PHP_VERSION_ID >= 80_600
            && class_exists(Context::class)
            && class_exists(StreamPollHandle::class)
            && method_exists(Context::class, 'wait')
        );
    }

    public function __construct(Backend $backend = Backend::Auto)
    {
        if (!self::isSupported()) {
            throw new RuntimeException('Workerman\\Events\\Poll requires PHP 8.6+ with native Io\\Poll support.');
        }

        $this->context = new Context($backend);
        $this->backend = $this->context->getBackend();
        $this->scheduler = new SplPriorityQueue();
    }

    /**
     * 返回底层实际使用的 I/O 多路复用后端名称（如 Epoll / Kqueue / Poll）
     */
    public function getBackendName(): string
    {
        return $this->backend->name;
    }

    public function delay(float $delay, callable $func, array $args = []): int
    {
        $delay = $delay > 0 ? $delay : 0.0;
        $timerId = $this->timerId++;
        $runTime = microtime(true) + $delay;
        $this->scheduler->insert([$timerId, $runTime], -$runTime);
        $this->eventTimer[$timerId] = [$func, $args, null];
        return $timerId;
    }

    public function offDelay(int $timerId): bool
    {
        if (isset($this->eventTimer[$timerId])) {
            unset($this->eventTimer[$timerId]);
            return true;
        }
        return false;
    }

    public function repeat(float $interval, callable $func, array $args = []): int
    {
        if ($interval <= 0) {
            return 0;
        }
        $timerId = $this->timerId++;
        $runTime = microtime(true) + $interval;
        $this->scheduler->insert([$timerId, $runTime], -$runTime);
        $this->eventTimer[$timerId] = [$func, $args, $interval];
        return $timerId;
    }

    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    public function onReadable($stream, callable $func): void
    {
        $this->addStreamEvent($stream, 'read', $func);
    }

    public function offReadable($stream): bool
    {
        return $this->delStreamEvent($stream, 'read');
    }

    public function onWritable($stream, callable $func): void
    {
        $this->addStreamEvent($stream, 'write', $func);
    }

    public function offWritable($stream): bool
    {
        return $this->delStreamEvent($stream, 'write');
    }

    public function onSignal(int $signal, callable $func): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('pcntl_signal')) {
            return;
        }
        $this->signalEvents[$signal] = $func;
        // 关闭 restart_syscalls，确保阻塞中的 Context::wait() 会被信号打断
        pcntl_signal($signal, [$this, 'signalHandler'], false);
    }

    public function offSignal(int $signal): bool
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('pcntl_signal')) {
            return false;
        }
        pcntl_signal($signal, SIG_IGN, false);
        if (isset($this->signalEvents[$signal])) {
            unset($this->signalEvents[$signal]);
            return true;
        }
        return false;
    }

    public function signalHandler(int $signal): void
    {
        if (isset($this->signalEvents[$signal])) {
            $this->safeCall($this->signalEvents[$signal], [$signal]);
        }
    }

    public function deleteAllTimer(): void
    {
        $this->scheduler = new SplPriorityQueue();
        $this->eventTimer = [];
    }

    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    public function run(): void
    {
        $this->running = true;
        $canDispatchSignals = DIRECTORY_SEPARATOR === '/' && function_exists('pcntl_signal_dispatch');

        while ($this->running && $this->context !== null) {
            if ($canDispatchSignals && $this->signalEvents !== []) {
                pcntl_signal_dispatch();
                // @mago-expect analysis:impossible-condition
                if (!$this->running) {
                    break;
                }
            }

            $context = $this->context;
            $timeout = null;
            if ($this->eventTimer !== []) {
                $timeoutNs = $this->computeTimeoutNanoseconds();
                if ($timeoutNs !== null) {
                    $timeout = Duration::fromNanoseconds($timeoutNs);
                }
            }
            if ($this->eventTimer === [] && $this->streamWatcherCount === 0) {
                // 无 fd 且无定时器时避免无限阻塞，确保信号能被及时处理
                $timeout = Duration::fromNanoseconds(1_000_000_000);
            }

            try {
                $watchers = $context->wait($timeout);
            } catch (FailedPollWaitException $e) {
                if ($this->isInterrupted($e)) {
                    // 被 POSIX 信号打断，回到循环顶部立即派发信号
                    continue;
                }
                throw $e;
            }

            if ($this->eventTimer !== []) {
                $this->tick();
            }

            // @mago-expect analysis:redundant-logical-operation
            if ($watchers !== [] && $this->running) {
                $this->dispatch($watchers);
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
        foreach (array_keys($this->events) as $key) {
            $this->removeStreamWatcher($key);
        }
        $this->events = [];
        $this->streamWatcherCount = 0;

        foreach (array_keys($this->signalEvents) as $signal) {
            $this->offSignal($signal);
        }
        $this->signalEvents = [];
        $this->deleteAllTimer();
        $this->context = null;
    }

    /**
     * @param resource $stream
     * @param 'read'|'write' $type
     */
    private function addStreamEvent($stream, string $type, callable $func): void
    {
        if (!is_resource($stream) || $this->context === null) {
            return;
        }
        $key = (int) $stream;

        // 防止底层 fd 编号被回收复用后残留旧 Watcher
        if (isset($this->events[$key]) && $this->events[$key]['fd'] !== $stream) {
            $this->removeStreamWatcher($key);
            unset($this->events[$key]);
        }

        if (!isset($this->events[$key])) {
            $this->events[$key] = [
                'fd' => $stream,
                'read' => null,
                'write' => null,
                'handle' => null,
                'watcher' => null,
            ];
        }

        $this->events[$key][$type] = $func;

        try {
            $this->syncStreamWatcher($key);
        } catch (Throwable $e) {
            $this->removeStreamWatcher($key);
            unset($this->events[$key]);
            $this->handleError($e);
        }
    }

    /**
     * @param resource $stream
     * @param 'read'|'write' $type
     */
    private function delStreamEvent($stream, string $type): bool
    {
        if (!is_resource($stream)) {
            return false;
        }
        $key = (int) $stream;
        if (!isset($this->events[$key])) {
            return false;
        }
        if ($this->events[$key]['fd'] !== $stream) {
            $this->removeStreamWatcher($key);
            unset($this->events[$key]);
            return false;
        }

        $this->events[$key][$type] = null;

        if ($this->events[$key]['read'] === null && $this->events[$key]['write'] === null) {
            $this->removeStreamWatcher($key);
            unset($this->events[$key]);
            return true;
        }

        try {
            $this->syncStreamWatcher($key);
        } catch (Throwable $e) {
            $this->removeStreamWatcher($key);
            unset($this->events[$key]);
            $this->handleError($e);
            return false;
        }
        return true;
    }

    private function syncStreamWatcher(int $key): void
    {
        $context = $this->context;
        if ($context === null) {
            return;
        }
        $entry = $this->events[$key];
        $events = [];
        if ($entry['read'] !== null) {
            $events[] = Event::Read;
        }
        if ($entry['write'] !== null) {
            $events[] = Event::Write;
        }

        if ($events === []) {
            $this->removeStreamWatcher($key);
            return;
        }

        $watcher = $entry['watcher'];
        if ($watcher !== null && $watcher->isActive()) {
            if (!$this->sameEvents($watcher->getWatchedEvents(), $events)) {
                $watcher->modifyEvents($events);
            }
            return;
        }

        $this->removeStreamWatcher($key);
        $handle = new StreamPollHandle($entry['fd']);
        $newWatcher = $context->add($handle, $events, $key);

        $this->events[$key]['handle'] = $handle;
        $this->events[$key]['watcher'] = $newWatcher;
        $this->streamWatcherCount++;
    }

    private function removeStreamWatcher(int $key): void
    {
        if (!isset($this->events[$key])) {
            return;
        }
        $watcher = $this->events[$key]['watcher'];
        if ($watcher !== null) {
            try {
                if ($watcher->isActive()) {
                    $watcher->remove();
                }
            } catch (Throwable) {
                // @mago-expect lint:no-empty-catch-clause Watcher 已失效时忽略
            }
            $this->events[$key]['watcher'] = null;
            if ($this->streamWatcherCount > 0) {
                $this->streamWatcherCount--;
            }
        }
        $this->events[$key]['handle'] = null;
    }

    private function tick(): void
    {
        /** @var list<array{0: int, 1: float}> $reinsert */
        $reinsert = [];
        while (!$this->scheduler->isEmpty()) {
            [$timerId, $runTime] = $this->scheduler->top();
            $timeNow = microtime(true);
            if ($runTime > $timeNow) {
                break;
            }
            $this->scheduler->extract();

            if (!isset($this->eventTimer[$timerId])) {
                continue;
            }

            [$func, $args, $interval] = $this->eventTimer[$timerId];
            if ($interval === null) {
                unset($this->eventTimer[$timerId]);
            }
            if ($interval !== null) {
                $reinsert[] = [$timerId, $timeNow + $interval];
            }

            $this->safeCall($func, $args);
            if (!$this->running) {
                return;
            }
        }

        foreach ($reinsert as [$timerId, $nextRunTime]) {
            if (!isset($this->eventTimer[$timerId])) {
                continue;
            }
            $this->scheduler->insert([$timerId, $nextRunTime], -$nextRunTime);
        }
    }

    private function computeTimeoutNanoseconds(): ?int
    {
        while (!$this->scheduler->isEmpty()) {
            [$timerId, $runTime] = $this->scheduler->top();
            if (!isset($this->eventTimer[$timerId])) {
                $this->scheduler->extract();
                continue;
            }
            $left = $runTime - microtime(true);
            if ($left <= 0) {
                return 0;
            }
            return (int) ceil($left * 1_000_000_000);
        }

        if ($this->streamWatcherCount === 0) {
            // 无 fd 且无定时器时避免无限阻塞，确保信号能被及时处理
            return 1_000_000_000;
        }
        return null;
    }

    /**
     * @param Watcher[] $watchers
     */
    private function dispatch(array $watchers): void
    {
        foreach ($watchers as $watcher) {
            // @mago-expect analysis:mixed-assignment
            $key = $watcher->getData();
            if (!is_int($key) || !isset($this->events[$key])) {
                continue;
            }

            $entry = $this->events[$key];
            $readCb = $entry['read'];
            $writeCb = $entry['write'];
            $fd = $entry['fd'];

            // 最热路径：仅注册读回调，短路优先检测 Event::Read，1 次 hasTriggered() 即可完成
            if ($readCb !== null && $writeCb === null) {
                if (
                    $watcher->hasTriggered(Event::Read)
                    || $watcher->hasTriggered(Event::ReadHangUp)
                    || $watcher->hasTriggered(Event::Error)
                    || $watcher->hasTriggered(Event::HangUp)
                ) {
                    $this->callStreamCallback($readCb, $fd);
                }
                continue;
            }

            // 次热路径：仅注册写回调
            if ($readCb === null && $writeCb !== null) {
                if (
                    $watcher->hasTriggered(Event::Write)
                    || $watcher->hasTriggered(Event::Error)
                    || $watcher->hasTriggered(Event::HangUp)
                    || $watcher->hasTriggered(Event::ReadHangUp)
                ) {
                    $this->callStreamCallback($writeCb, $fd);
                }
                continue;
            }

            // 同时注册读写回调
            $readHangUp = $watcher->hasTriggered(Event::ReadHangUp);
            $failed = $readHangUp || $watcher->hasTriggered(Event::Error) || $watcher->hasTriggered(Event::HangUp);

            if ($readCb !== null && ($failed || $watcher->hasTriggered(Event::Read))) {
                $this->callStreamCallback($readCb, $fd);
            }
            // @mago-expect analysis:redundant-logical-operation
            if (!isset($this->events[$key]) || !$this->running) {
                continue;
            }

            if ($writeCb !== null && ($failed || $watcher->hasTriggered(Event::Write))) {
                $this->callStreamCallback($writeCb, $fd);
            }
        }
    }

    /**
     * @param resource $stream
     */
    private function callStreamCallback(callable $func, $stream): void
    {
        try {
            $func($stream);
        } catch (Throwable $e) {
            $this->handleError($e);
        }
    }

    private function isInterrupted(FailedPollWaitException $e): bool
    {
        return $e->getCode() === FailedPollOperationException::ERROR_INTERRUPTED
        || str_contains(strtolower($e->getMessage()), 'interrupted system call');
    }

    /**
     * @param Event[] $watched
     * @param Event[] $expected
     */
    private function sameEvents(array $watched, array $expected): bool
    {
        if (count($watched) !== count($expected)) {
            return false;
        }
        foreach ($expected as $event) {
            if (!in_array($event, $watched, true)) {
                return false;
            }
        }
        return true;
    }

    private function safeCall(callable $func, array $args = []): void
    {
        try {
            $func(...$args);
        } catch (Throwable $e) {
            $this->handleError($e);
        }
    }

    private function handleError(Throwable $e): void
    {
        if ($this->errorHandler === null) {
            Worker::safeEcho((string) $e . "\n");
            return;
        }

        ($this->errorHandler)($e);
    }
}
