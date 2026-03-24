<?php

declare(strict_types=1);

/**
 * Résout vendor/autoload_runtime.php : soit ./vendor (installation classique),
 * soit ../../durable-symfony-vendor quand composer.json définit vendor-dir hors du dépôt path (monorepo).
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
