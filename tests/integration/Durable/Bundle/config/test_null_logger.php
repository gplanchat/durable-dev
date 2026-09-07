<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Replaces the logger with NullLogger to suppress the [info] logs during the tests.
 */
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('logger', NullLogger::class)
    ;
};
