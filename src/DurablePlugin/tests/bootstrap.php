<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Gplanchat\\Durable\\Plugin\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
