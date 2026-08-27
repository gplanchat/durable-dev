<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Remplace le logger par NullLogger pour supprimer les logs [info] pendant les tests.
 */
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('logger', NullLogger::class)
    ;
};
