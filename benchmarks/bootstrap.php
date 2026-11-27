<?php

declare(strict_types=1);

/**
 * Shared autoload bootstrap for benchmarks.
 *
 * On PHP 8.6+, loads Composer's autoloader directly.
 * On PHP 8.1-8.5 (used only for Event/Select baseline comparison), registers
 * Workerman's PSR-4 autoloader directly so platform_check.php (>=8.6) does not block.
 */

$root = dirname(__DIR__);

if (PHP_VERSION_ID >= 80_600) {
    require_once $root . '/vendor/autoload.php';
    return;
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefixes = [
        'Workerman\\Coroutine\\' => $root . '/vendor/workerman/coroutine/src/',
        'Workerman\\' => $root . '/vendor/workerman/workerman/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
