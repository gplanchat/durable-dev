<?php

declare(strict_types=1);

use Gplanchat\Durable\Plugin\Controller\AdminDashboardController;
use Gplanchat\Durable\Plugin\Dashboard\TemporalEventsDashboardDataProvider;
use Gplanchat\Durable\Plugin\EventListener\AdminMenuListener;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(TemporalEventsDashboardDataProvider::class)
        ->arg('$workflowServiceClient', service('durable.temporal.workflow_service_client')->nullOnInvalid())
        ->arg('$connection', service('durable.temporal.connection')->nullOnInvalid())
        ->arg('$historyCursor', service('Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor')->nullOnInvalid())
    ;

    $services
        ->set(AdminDashboardController::class)
        ->autowire()
        ->tag('controller.service_arguments')
    ;

    // Sylius admin menu entry (safe no-op if event payload is unexpected).
    $services
        ->set(AdminMenuListener::class)
        ->autowire()
        ->tag('kernel.event_listener', [
            'event' => 'sylius.menu.admin.main',
            'method' => 'addDashboardItem',
        ])
    ;
};
