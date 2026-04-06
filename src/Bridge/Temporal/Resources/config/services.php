<?php

declare(strict_types=1);

use Gplanchat\Bridge\Temporal\Messenger\TemporalTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autoconfigure()
        ->autowire()
    ;

    $services->set(TemporalTransportFactory::class)
        ->args([tagged_iterator('messenger.transport_factory')])
        ->tag('messenger.transport_factory', ['priority' => -100])
    ;
};
