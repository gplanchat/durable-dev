<?php

declare(strict_types=1);

use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Plugin\Controller\AdminDashboardController;
use Gplanchat\Durable\Plugin\EventListener\AdminMenuListener;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Le catalogue est absent quand aucun backend n'est lisible : le bundle n'en enregistre alors
    // aucun, et la page doit le dire plutôt que d'échouer au montage.
    $services
        ->set(RunDashboard::class)
        ->arg('$catalog', service(WorkflowRunCatalogInterface::class)->nullOnInvalid())
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
