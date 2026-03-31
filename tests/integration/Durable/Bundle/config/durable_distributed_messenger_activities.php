<?php

declare(strict_types=1);

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\WorkflowRunMessage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->import('test_null_logger.php');
    $container->extension('framework', [
        'secret' => 'test',
        'http_method_override' => false,
        'test' => true,
        'messenger' => [
            'transports' => [
                'sync' => 'sync://',
                'durable_workflows' => 'in-memory://',
                'durable_activities' => 'in-memory://',
            ],
            'routing' => [
                WorkflowRunMessage::class => 'durable_workflows',
                ActivityMessage::class => 'durable_activities',
                DeliverWorkflowSignalMessage::class => 'sync',
                DeliverWorkflowUpdateMessage::class => 'sync',
                FireWorkflowTimersMessage::class => 'durable_workflows',
            ],
        ],
    ]);
    $container->extension('durable', [
        'activity_transport' => [
            'type' => 'messenger',
            'transport_name' => 'durable_activities',
        ],
    ]);
};
