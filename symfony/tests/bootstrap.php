<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);
$autoloads = [
    $projectRoot.'/vendor/autoload.php',
    $projectRoot.'/../../durable-symfony-vendor/autoload.php',
];
foreach ($autoloads as $file) {
    if (is_file($file)) {
        require $file;
        break;
    }
}

if (!class_exists(Dotenv::class)) {
    throw new \RuntimeException('symfony/dotenv introuvable : exécutez `composer install`.');
}

// Charge .env + .env.test (et .env.test.local si présent), comme le point d'entrée HTTP.
// Cela garantit que les variables (DEFAULT_URI, etc.) sont disponibles pour WebTestCase.
(new Dotenv())->bootEnv($projectRoot.'/.env');
