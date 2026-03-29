<?php

declare(strict_types=1);

use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
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
            ],
            'routing' => [
                WorkflowRunMessage::class => 'sync',
                DeliverWorkflowSignalMessage::class => 'sync',
                DeliverWorkflowUpdateMessage::class => 'sync',
            ],
        ],
    ]);
    $container->extension('durable', [
        'distributed' => true,
    ]);
};
