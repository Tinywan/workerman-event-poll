# workerman-event-poll

Workerman 5.x event loop driver based on the **PHP 8.6+ native `Io\Poll` API**.

基于 **PHP 8.6+ 原生 `Io\Poll` API** 的 Workerman 5.x 事件循环驱动，零第三方 C 扩展依赖，开箱即用。

## Features

- **零 PECL 扩展依赖**：直接使用 PHP 8.6 内置引擎级 `Io\Poll`（Linux 自动选用 `Epoll`，macOS/BSD 自动选用 `Kqueue`，Windows 自动选用 `WSAPoll`），彻底突破 `stream_select` 的 1024 FD 上限。
- **完整实现 `EventInterface`**：完整支持 TCP / HTTP / WebSocket、延迟与周期定时器、POSIX 信号处理与自定义异常捕获。
- **高性能数据结构**：使用 `SplPriorityQueue` 最小堆调度定时器；同一连接仅维护单个 `Watcher`，通过 `Watcher::modifyEvents()` 原地更新读写监听。
- **及时响应 POSIX 信号**：注册信号时关闭 `restart_syscalls`，确保阻塞中的 `Context::wait()` 能被信号立即打断并派发。

## Requirements

| Environment | Requirements |
|---|---|
| PHP | `>= 8.6`（内置 `Io\Poll` 与 `Time\Duration`） |
| Workerman | `^5.0` |
| POSIX (Linux / macOS) | `ext-pcntl`, `ext-posix`（Workerman 运行所需） |

## Installation

```bash
composer require tinywan/workerman-event-poll
```

## Quick Start

### 方式一：全局指定事件循环

```php
use Workerman\Events\Poll;
use Workerman\Worker;

Worker::$eventLoopClass = Poll::class;
```

### 方式二：按 Worker 单独指定（推荐）

```php
use Workerman\Events\Poll;
use Workerman\Worker;

$worker = new Worker('http://0.0.0.0:2345');

if (Poll::isSupported()) {
    $worker->eventLoop = Poll::class;
}
```

启动服务后执行 `status` 命令，确认 `event-loop` 列显示为 `poll`：

```bash
php examples/http.php status
```

## Examples

仓库 [`examples/`](examples/) 目录内置了三个开箱即用的协议示例（对齐 Workerman 官方入门实例）：

### 1. HTTP 服务（[`examples/http.php`](examples/http.php)）

```php
<?php

declare(strict_types=1);

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
```

启动与验证：

```bash
php examples/http.php start
curl "http://127.0.0.1:2345/ping?name=workerman"
```

### 2. WebSocket 服务（[`examples/websocket.php`](examples/websocket.php)）

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Events\Poll;
use Workerman\Worker;

$worker = new Worker('websocket://0.0.0.0:2000');
$worker->name = 'poll-websocket';
$worker->count = 4;
$worker->eventLoop = Poll::class;

$worker->onConnect = static function (TcpConnection $connection): void {
    $connection->send('connected via ' . Poll::class);
};

$worker->onMessage = static function (TcpConnection $connection, string $data): void {
    $connection->send('hello ' . $data);
};

Worker::runAll();
```

启动与验证（浏览器控制台）：

```bash
php examples/websocket.php start
```

```js
const ws = new WebSocket('ws://127.0.0.1:2000');
ws.onmessage = (e) => console.log('recv:', e.data);
ws.onopen = () => ws.send('world');
```

### 3. TCP 服务（[`examples/tcp.php`](examples/tcp.php)）

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Events\Poll;
use Workerman\Worker;

$worker = new Worker('tcp://0.0.0.0:2347');
$worker->name = 'poll-tcp';
$worker->count = 4;
$worker->eventLoop = Poll::class;

$worker->onMessage = static function (TcpConnection $connection, string $data): void {
    $connection->send('hello ' . $data);
};

Worker::runAll();
```

启动与验证：

```bash
php examples/tcp.php start
telnet 127.0.0.1 2347
```

## Comparison with Built-in Drivers

| Driver | Backend | Extension | Notes |
|---|---|---|---|
| **Poll (this package)** | Native `Io\Poll` (`Epoll`/`Kqueue`/`WSAPoll`) | None | PHP 8.6+ 内置，仅需 Composer 安装 |
| Select | `stream_select` | None | ~1024 FD 上限，$O(n)$ 轮询 |
| Ev / Event | `libev` / `libevent` | PECL C 扩展 | 高性能，但需编译安装扩展 |
| Swoole / Swow | 自研引擎 | 完整运行时扩展 | 改变 PHP 运行时模型 |

## Development & Quality Checks

本项目使用 **Mago** 进行代码格式化、Lint 与静态分析，使用 **Pest** 编写单元测试：

```bash
composer install

composer format        # 使用 Mago 格式化代码
composer format:check  # 检查代码格式
composer lint          # 运行 Mago Linter
composer analyse       # 运行 Mago 静态分析
composer test          # 运行 Pest 单元测试
composer check         # 一键执行全部检查 (format:check + lint + analyse + test)
```

> 说明：[`stubs/io-poll.php`](stubs/io-poll.php) 仅用于补充 Mago 静态分析对 PHP 8.6 原生 `Io\Poll` / `Time\Duration` 的类型定义，不会参与 Composer 自动加载与运行时执行。

## License

MIT
