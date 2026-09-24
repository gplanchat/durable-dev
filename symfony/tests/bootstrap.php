<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);
if (is_file($projectRoot.'/vendor/autoload.php')) {
    require $projectRoot.'/vendor/autoload.php';
}

if (!class_exists(Dotenv::class)) {
    throw new \RuntimeException('symfony/dotenv not found: run `composer install`.');
}

// Loads .env + .env.test (and .env.test.local when present), like the HTTP entry point.
// This guarantees that the variables (DEFAULT_URI, etc.) are available to WebTestCase.
(new Dotenv())->bootEnv($projectRoot.'/.env');
