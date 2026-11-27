# workerman-event-poll

Workerman 5.x event loop driver based on the **PHP 8.6+ native `Io\Poll` API**.

基于 PHP 8.6+ 原生 I/O 轮询 API（`Io\Poll`）的 Workerman 5.x 事件循环驱动，零第三方 C 扩展依赖。

## Features

- Uses PHP 8.6+ native engine-level `Io\Poll` implementation: `Epoll` (Linux), `Kqueue` (macOS/BSD), `WSAPoll` (Windows) — no PECL `event`/`ev` extension needed and breaks the 1024 FD limit of `stream_select`.
- Drop-in replacement implementing `Workerman\Events\EventInterface`: TCP / HTTP / WebSocket / timers / POSIX signals / error handling.
- Min-heap (`SplPriorityQueue`) timer scheduler and single-watcher-per-stream updates via `Watcher::modifyEvents()`.
- Disables `restart_syscalls` when registering POSIX signals so blocking `Context::wait()` is interrupted immediately for signal dispatch.

## Requirements

| Environment | Requirements |
|---|---|
| PHP | `>= 8.6` (with native `Io\Poll`) |
| Workerman | `^5.0` |
| POSIX (Linux/macOS) | `ext-pcntl`, `ext-posix` (required by Workerman) |

## Installation

```bash
composer require tinywan/workerman-event-poll
```

## Usage

```php
use Workerman\Worker;
use Workerman\Events\Poll;

Worker::$eventLoopClass = Poll::class;

$worker = new Worker('http://0.0.0.0:8080');
$worker->onMessage = function ($connection, $request) {
    $connection->send('hello');
};

Worker::runAll();
```

Optional runtime check:

```php
if (Poll::isSupported()) {
    Worker::$eventLoopClass = Poll::class;
}
```

Verify with `php examples/server.php status` — the `event-loop` column shows `poll`.

## Examples

```bash
composer install

# Standalone event loop self-test (stream read/write + timers)
php examples/loop-test.php

# HTTP server (:2345)
php examples/http.php start

# WebSocket server (:2000)
php examples/websocket.php start

# TCP server (:2347)
php examples/tcp.php start

# Multi-protocol server (TCP :2345 + HTTP :8080 + WebSocket :2000 + Timer)
php examples/server.php start
```

## Development & Quality Checks

```bash
composer format        # Format code with Mago
composer format:check  # Check formatting
composer lint          # Run Mago linter
composer analyse       # Run Mago static analyzer
composer test          # Run Pest unit tests
composer check         # Run all checks (format:check, lint, analyse, test)
```

## Comparison with built-in drivers

| Driver | Backend | Extension | Notes |
|---|---|---|---|
| **Poll (this package)** | Native `Io\Poll` (`Epoll`/`Kqueue`/`WSAPoll`) | none | PHP 8.6+ built-in, composer-only install |
| Select | `stream_select` | none | ~1024 fd limit, O(n) |
| Ev / Event | libev / libevent | pecl C extension | high performance, needs compiling |
| Swoole / Swow | own engines | full runtime extension | changes the runtime model |

## License

MIT
