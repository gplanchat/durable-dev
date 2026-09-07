<?php

declare(strict_types=1);

/**
 * Resolves vendor/autoload_runtime.php: either ./vendor (classic installation),
 * or ../../durable-symfony-vendor when composer.json sets vendor-dir outside the path repository (monorepo).
 */
$symfonyRoot = __DIR__;
$candidates = [
    $symfonyRoot.'/vendor/autoload_runtime.php',
    realpath($symfonyRoot.'/../../durable-symfony-vendor/autoload_runtime.php') ?: null,
];

foreach ($candidates as $path) {
    if (null !== $path && is_file($path)) {
        require_once $path;

        return;
    }
}

throw new LogicException(
    'Dependencies are missing. From the symfony/ directory, run: composer install',
);
