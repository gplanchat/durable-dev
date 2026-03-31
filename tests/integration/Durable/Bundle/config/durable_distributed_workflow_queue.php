<?php

declare(strict_types=1);

use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\WorkflowRunMessage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Workflows dans une file in-memory : le test consomme explicitement (activité drainée entre deux messages).
 */
return static function (ContainerConfigurator $container): void {
    $container->import('test_null_logger.php');
    $container->extension('framework', [
        'secret' => 'test',
        'http_method_override' => false,
        'test' => true,
        'messenger' => [
            'transports' => [
                'workflow_jobs' => 'in-memory://',
                'sync' => 'sync://',
            ],
            'routing' => [
                WorkflowRunMessage::class => 'workflow_jobs',
                DeliverWorkflowSignalMessage::class => 'sync',
                DeliverWorkflowUpdateMessage::class => 'sync',
                FireWorkflowTimersMessage::class => 'workflow_jobs',
            ],
        ],
    ]);
    $container->extension('durable', [
    ]);
};
