<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$autoloads = [
    $projectRoot.'/vendor/autoload.php',
    $projectRoot.'/../../durable-symfony-vendor/autoload.php',
];
foreach ($autoloads as $file) {
    if (is_file($file)) {
        require $file;

        return;
    }
}

throw new \RuntimeException('Autoload Composer introuvable : exécutez `composer install` depuis symfony/.');
