# workerman-event-Poll

Workerman 5.x event loop driver based on the **PHP 8.6 `Io\Poll` API**, with Symfony polyfill support for PHP 8.1+.

基于 PHP 8.6 新 I/O 轮询 API（`Io\Poll`）的 Workerman 5.x 事件循环驱动，PHP 8.1+ 通过 Symfony polyfill 支持。

## Features

- On **PHP 8.6+** it uses the native engine-level implementation: `epoll` (Linux), `kqueue` (macOS/BSD), `WSAPoll` (Windows). No C extension needed.
- On **PHP 8.1 ~ 8.5** it runs through [`symfony/polyfill-io-poll`](https://github.com/symfony/polyfill-io-poll), same API, same driver code.
- Drop-in replacement: implements `Workerman\Events\EventInterface`, works with TCP / HTTP / WebSocket / timers / signals.

## Requirements

| Environment | Requirements |
|---|---|
| PHP 8.6+ | nothing extra |
| PHP 8.1 ~ 8.5 | `symfony/polyfill-io-poll` (installed automatically); add `symfony/polyfill-time` if you pin the polyfill to `1.x-dev` |
| POSIX (Linux/macOS) | `ext-pcntl`, `ext-posix` (Workerman itself requires them) |

> Note: `symfony/polyfill-io-poll` releases `^1.41` and `1.x-dev` use different `Context::wait()` signatures. This driver detects the signature at runtime via reflection, so **both work out of the box**.

## Installation

```bash
composer require tinywan/workerman-event-poll
```

## Usage

```php
use Workerman\Worker;
use Tinywan\WorkermanEventPoll\Poll;

Worker::$eventLoopClass = Poll::class;

$worker = new Worker('http://0.0.0.0:8080');
$worker->onMessage = function ($connection, $request) {
    $connection->send('hello');
};

Worker::runAll();
```

Verify with `php examples/server.php status` — the `event-loop` column shows `poll`.

## Examples

```bash
composer install

# event loop self-test (stream read/write + timers)
php examples/loop-test.php

# TCP (2345) + HTTP (8080) demo server
php examples/server.php start
```

## Comparison with built-in drivers

| Driver | Backend | Extension | Notes |
|---|---|---|---|
| **Poll (this package)** | Io\Poll (native epoll/kqueue on 8.6) | none | composer-only install, auto-upgrades on PHP 8.6 |
| Select | stream_select | none | ~1024 fd limit, O(n) |
| Ev / Event | libev / libevent | pecl C extension | high performance, needs compiling |
| Swoole / Swow | own engines | full runtime extension | changes the runtime model |

On PHP < 8.6 the polyfill performs like the Select driver (its value is API compatibility and a zero-change upgrade path). For maximum performance on PHP 8.1~8.5 keep using `Ev`/`Event`.

## Known limitations

- Signals are handled via `pcntl_async_signals` (the polyfill only watches stream handles), so a signal may not interrupt a blocking `wait()` until timeout or I/O.
- Edge-triggered events are not supported by the polyfill (level-triggered only, same as Workerman's built-in drivers).

## Testing matrix

| Runtime | Unit test | TCP / HTTP |
|---|---|---|
| PHP 8.4 + polyfill | pass | pass |
| PHP 8.6.0beta1 native | pass | pass |

## License

MIT
