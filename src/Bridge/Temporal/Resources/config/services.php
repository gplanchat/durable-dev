<?php

declare(strict_types=1);

use Gplanchat\Bridge\Temporal\Command\RunTemporalJournalWorkerCommand;
use Gplanchat\Bridge\Temporal\Messenger\TemporalJournalTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autoconfigure()
        ->autowire()
    ;

    $services->set(TemporalJournalTransportFactory::class)
        ->tag('messenger.transport_factory')
    ;

    $services->set(RunTemporalJournalWorkerCommand::class)
        ->tag('console.command')
    ;
};
