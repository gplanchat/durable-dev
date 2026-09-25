<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\Http\Psr18Http;
use Gplanchat\Bridge\Temporal\Store\TemporalReadThroughEventStore;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\TemporalRuntimeAssembly;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Where the journal lives: the event store for the configured backend, and the child-to-parent link store.
 *
 * Moved verbatim out of {@see DurableExtension} (#342), which calls these in its load() order.
 *
 * @internal
 */
final class EventStores
{
    public static function registerChildWorkflowParentLinkStore(ContainerBuilder $container): void
    {
        $container->register('durable.child_workflow_parent_link_store', InMemoryChildWorkflowParentLinkStore::class)
            ->setPublic(true)
        ;

        $container->setAlias(ChildWorkflowParentLinkStoreInterface::class, 'durable.child_workflow_parent_link_store')
            ->setPublic(true)
        ;
    }

    /**
     * The Temporal graph behind a DSN: connection, client, the bridge's assembly and what it serves.
     *
     * @param array<string, mixed> $temporalConfig
     */
    public static function registerTemporalEventStore(ContainerBuilder $container, array $temporalConfig, string $dsn, bool $journal): void
    {
        $container->register('durable.temporal.connection', TemporalConnection::class)
            ->setFactory([TemporalConnection::class, 'fromDsn'])
            ->setArguments([$dsn])
        ;

        $client = $container->register('durable.temporal.workflow_service_client', WorkflowServiceClientInterface::class)
            ->setFactory([WorkflowServiceClientFactory::class, 'create'])
            ->setArguments([
                new Reference('durable.temporal.connection'),
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
        ;
        // transport=guzzle over the application's client; any other transport ignores it.
        $guzzleClient = $temporalConfig['guzzle_client'] ?? null;
        if (\is_string($guzzleClient) && '' !== $guzzleClient) {
            $client->addArgument(new Reference($guzzleClient));
        }
        // transport=http over the application's PSR-18 client instead of curl.
        $psr18 = $temporalConfig['psr18_client'] ?? null;
        if (\is_string($psr18) && '' !== $psr18) {
            $psr17 = new Reference(\is_string($temporalConfig['psr17_factory'] ?? null) ? $temporalConfig['psr17_factory'] : $psr18);
            $client->setArgument(2, $client->getArguments()[2] ?? null);
            $client->setArgument(3, new Definition(Psr18Http::class, [new Reference($psr18), $psr17, $psr17]));
        }

        // The graph is the bridge's (#356): each service below is one of the assembly's
        // objects, under the id it always had.
        $container->register(TemporalRuntimeAssembly::class)
            ->setArguments([
                new Reference('durable.temporal.workflow_service_client'),
                new Reference('durable.temporal.connection'),
                new Reference(\Gplanchat\Durable\WorkflowRegistry::class),
                new Reference(WorkflowDefinitionLoader::class),
            ])
            ->setPublic(false)
        ;
        $fromAssembly = static fn(string $id, string $class, string $method, bool $public = false, array $arguments = []): Definition => $container
            ->register($id, $class)
            ->setFactory([new Reference(TemporalRuntimeAssembly::class), $method])
            ->setArguments($arguments)
            ->setPublic($public);

        $fromAssembly(WorkflowServiceActivityRpc::class, WorkflowServiceActivityRpc::class, 'activityRpc');
        $fromAssembly(WorkflowServiceExecutionRpc::class, WorkflowServiceExecutionRpc::class, 'executionRpc');
        $fromAssembly(WorkflowServiceNexusRpc::class, WorkflowServiceNexusRpc::class, 'nexusRpc');
        $fromAssembly(TemporalHistoryCursor::class, TemporalHistoryCursor::class, 'historyCursor');
        $fromAssembly(WorkflowClient::class, WorkflowClient::class, 'workflowClient');
        $container->setAlias(WorkflowClientInterface::class, WorkflowClient::class)
            ->setPublic(false)
        ;

        $fromAssembly('durable.run_catalog.temporal', TemporalWorkflowRunCatalog::class, 'runCatalog');
        if ($journal) {
            $container->setAlias(WorkflowRunCatalogInterface::class, 'durable.run_catalog.temporal')->setPublic(true);
        }

        $fromAssembly(WorkflowTaskRunner::class, WorkflowTaskRunner::class, 'workflowTaskRunner', true);
        // Private: the journal transport takes it by reference, nothing pulls it by id (#337).
        $fromAssembly(WorkflowTaskProcessor::class, WorkflowTaskProcessor::class, 'workflowTaskProcessor');
        $fromAssembly('durable.event_store.temporal', TemporalReadThroughEventStore::class, 'readThroughEventStore', false, [new Reference('durable.event_store.inner')]);

        if ($journal) {
            $container->setAlias(EventStoreInterface::class, 'durable.event_store.temporal')->setPublic(true);
        }
    }
}
