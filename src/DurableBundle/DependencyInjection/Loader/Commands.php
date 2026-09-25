<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Gplanchat\Durable\Bundle\Command\DiagnoseExecutionCommand;
use Gplanchat\Durable\Bundle\Command\DurableWorkerCommand;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\EventListener\RefuseResetOnInMemoryTransportListener;
use Gplanchat\Durable\Bundle\Messenger\DurableWorkerInspection;
use Gplanchat\Durable\Observation\KeyPatternPayloadRedactor;
use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * The console side: the payload redactor they show payloads through, durable:execution:diagnose, durable:worker and the worker's reset guard.
 *
 * Moved verbatim out of {@see DurableExtension} (#342), which calls these in its load() order.
 *
 * @internal
 */
final class Commands
{
    /**
     * @param array<string, mixed> $config
     */
    public static function registerCommands(ContainerBuilder $container, array $config): void
    {
        // What the diagnose command and the profiler panel may show of a payload. An application
        // replaces it by aliasing the interface to its own implementation.
        $container->register('durable.payload_redactor', KeyPatternPayloadRedactor::class)->setPublic(false);
        // The application's services.yaml is loaded before this extension: keep its alias.
        if (!$container->hasAlias(PayloadRedactorInterface::class) && !$container->hasDefinition(PayloadRedactorInterface::class)) {
            $container->setAlias(PayloadRedactorInterface::class, 'durable.payload_redactor');
        }

        $isTemporalNative = DurableExtension::isTemporalNative($config);

        CoreServices::registerActivityProcessor($container, $config, $isTemporalNative);

        $container->register(DiagnoseExecutionCommand::class)
            ->setArguments([
                new Reference(WorkflowMetadataStore::class),
                new Reference(EventStoreInterface::class),
                new Reference(ChildWorkflowParentLinkStoreInterface::class),
                new Reference('durable.temporal.connection', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference(PayloadRedactorInterface::class),
                // On Temporal native the metadata store is per process: an empty row means nothing.
                $isTemporalNative,
            ])
            ->addTag('console.command')
        ;

        $activityTransport = 'messenger' === ($config['activity_transport']['type'] ?? '') && !$isTemporalNative
            ? ($config['activity_transport']['transport_name'] ?? 'durable_activities')
            : null;
        $container->register(DurableWorkerCommand::class)
            ->setArguments([
                new Reference('messenger.senders_locator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('messenger.receiver_locator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $activityTransport,
                $isTemporalNative,
            ])
            ->addTag('console.command', ['command' => 'durable:worker'])
        ;

        // What a starting worker consumes, for the listeners that guard durable:worker and
        // messenger:consume alike (#444): the first runs the second without a console event.
        $container->register(DurableWorkerInspection::class)
            ->setArguments([
                new Reference('messenger.senders_locator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('messenger.receiver_locator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $activityTransport,
                $isTemporalNative,
                new Reference('event_dispatcher'),
            ])
            ->setPublic(false)
        ;
        $container->register(RefuseResetOnInMemoryTransportListener::class)
            ->setArguments([new Reference(DurableWorkerInspection::class)])
            ->addTag('kernel.event_listener', ['event' => WorkerStartedEvent::class])
            ->setPublic(false)
        ;
    }
}
