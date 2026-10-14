<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowClientPort;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\Handler\LinkedChildFinalizer;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Query\ReadOnlyReplay;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryWorkflowClient;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\WorkflowRegistry;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * {@see WorkflowClientPort} on the backend the bundle runs: Temporal's when the engine is
 * Temporal-native, the journal client over the configured stores otherwise (in-memory or DBAL).
 * Illuminate is the Laravel host's backend and has no part here.
 *
 * @internal
 */
final class ClientServices
{
    private function __construct() {}

    /**
     * Called after the stores are registered. The references resolve at compilation, when the
     * decorators and the DBAL stores are in place.
     *
     * @param array<string, mixed> $config
     */
    public static function registerWorkflowClientPort(ContainerBuilder $container, array $config): void
    {
        if (DurableExtension::isTemporalNative($config)) {
            $container->register('durable.client_port', TemporalWorkflowClientPort::class)
                ->setArguments([new Reference(WorkflowClient::class)])
                ->setPublic(false)
            ;
        } else {
            $events = new Reference(EventStoreInterface::class);
            $metadata = new Reference(WorkflowMetadataStore::class);
            $dispatcher = new Reference(WorkflowResumeDispatcher::class);

            $container->register('durable.client_port', InMemoryWorkflowClient::class)
                ->setArguments([
                    $metadata,
                    $dispatcher,
                    new Reference(WorkflowRunCatalogInterface::class),
                    $events,
                    new Definition(ReadOnlyReplay::class, [new Reference(ExecutionEngine::class), $events, $metadata, new Reference(WorkflowRegistry::class)]),
                    new Definition(LinkedChildFinalizer::class, [$events, $dispatcher, new Reference(ChildWorkflowParentLinkStoreInterface::class)]),
                    new Reference(ParentChildWorkflowCoordinatorInterface::class),
                    'dbal' === ($config['event_store']['type'] ?? 'in_memory') ? 'dbal' : 'in-memory',
                ])
                ->setPublic(false)
            ;
        }

        $container->setAlias(WorkflowClientPort::class, 'durable.client_port')->setPublic(false);
    }
}
