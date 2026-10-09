<?php

declare(strict_types=1);

// PHPUnit comes from the repository's vendor/ and has loaded its autoloader already; Magento's
// is added behind it, then Magento itself is booted once, by JournalHarness on first use.
require_once __DIR__ . '/../app/bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Gplanchat\\Durable\\MagentoBench\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
