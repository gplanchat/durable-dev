<?php

declare(strict_types=1);

/**
 * Loads vendor/autoload_runtime.php, or says what to run when the dependencies are missing.
 */
$path = __DIR__.'/vendor/autoload_runtime.php';
if (is_file($path)) {
    require_once $path;

    return;
}

throw new LogicException(
    'Dependencies are missing. From the symfony/ directory, run: composer install',
);
