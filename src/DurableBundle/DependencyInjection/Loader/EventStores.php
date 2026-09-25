<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\Http\Psr18Http;
use Gplanchat\Bridge\Temporal\Messenger\TemporalActivityWorkerTransport;
use Gplanchat\Bridge\Temporal\Messenger\TemporalJournalTransport;
use Gplanchat\Bridge\Temporal\Messenger\TemporalNexusWorkerTransport;
use Gplanchat\Bridge\Temporal\Store\TemporalReadThroughEventStore;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\TemporalRuntimeAssembly;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Where the journal lives: the event store for the configured backend, the in-memory run catalog and
 * metadata store, and the child-to-parent link store.
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

    /**
     * @param array<string, mixed> $config
     */
    public static function registerEventStore(ContainerBuilder $container, array $config): void
    {
        $temporalConfig = $config['temporal'] ?? [];
        $dsn = $temporalConfig['dsn'] ?? null;
        $hasDsn = \is_string($dsn) && '' !== $dsn;
        // A DBAL journal replaces this one further down in load(), and without a Temporal DSN nothing
        // reads the in-memory store: it is not registered (#342).
        if (!$hasDsn && 'dbal' === ($config['event_store']['type'] ?? 'in_memory')) {
            return;
        }

        $container->register('durable.event_store.inner', InMemoryEventStore::class)->setPublic(false);

        $journal = false !== ($temporalConfig['journal'] ?? true);
        if ($hasDsn) {
            self::registerTemporalEventStore($container, $temporalConfig, $dsn, $journal);

            // With no journal, we leave without an alias: `registerDbalStores` or `registerInMemoryRunCatalog`
            // will place theirs, further down in `load()`. It is `event_store` that says which one.
            return;
        }

        $container->setAlias(EventStoreInterface::class, 'durable.event_store.inner')->setPublic(true);
    }

    /**
     * The in-memory backend's catalog, as a last resort.
     *
     * It only registers itself if nobody has already placed a catalog: DBAL and Temporal come
     * first, each in its own block, and the guard is the alias they leave behind. A backend that
     * knows how to read its own executions has no use for this one.
     *
     * The catalog reads the **undecorated** journal to render a history, and the decorator feeds
     * it on writes. The two therefore point at `durable.event_store.inner` rather than at each
     * other — without which the container loops.
     *
     * What this clears: the dashboard displayed "no readable backend" on in-memory, for want of a
     * catalog, while the plugin claims to be neutral with respect to the backend. It really is
     * now, on all three.
     *
     * @see DUR037
     */
    public static function registerInMemoryRunCatalog(ContainerBuilder $container): void
    {
        if ($container->hasAlias(WorkflowRunCatalogInterface::class)) {
            return;
        }

        $container->register('durable.run_catalog.in_memory', InMemoryWorkflowRunCatalog::class)
            ->setArguments([new Reference('durable.event_store.inner')])
            ->setPublic(false)
        ;
        $catalog = new Reference('durable.run_catalog.in_memory');
        $container->setAlias(WorkflowRunCatalogInterface::class, 'durable.run_catalog.in_memory')->setPublic(true);
        $container->setAlias(WorkflowRunPickupProjectionInterface::class, 'durable.run_catalog.in_memory')->setPublic(false);

        $container->register('durable.event_store.in_memory.projecting', ProjectingEventStore::class)
            ->setArguments([new Reference('durable.event_store.inner'), $catalog])
            ->setPublic(false)
        ;
        $container->setAlias(EventStoreInterface::class, 'durable.event_store.in_memory.projecting')->setPublic(true);

        $container->register('durable.workflow_metadata_store.in_memory.projecting', ProjectingWorkflowMetadataStore::class)
            ->setArguments([new Reference('durable.workflow_metadata_store.inner'), $catalog])
            ->setPublic(false)
        ;
        $container->setAlias(WorkflowMetadataStore::class, 'durable.workflow_metadata_store.in_memory.projecting')->setPublic(true);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function registerTemporalMirrorInfrastructure(ContainerBuilder $container, array $config): void
    {
        $dsn = $config['temporal']['dsn'] ?? null;
        if (!\is_string($dsn) || '' === $dsn) {
            return;
        }
        if (!$container->hasDefinition('durable.temporal.workflow_service_client')) {
            return;
        }

        $container->register('durable.temporal.activity_worker', TemporalActivityWorker::class)
            ->setArguments([
                new Reference(WorkflowServiceActivityRpc::class),
                new Reference('durable.temporal.connection'),
                new Reference(ActivityMessageProcessor::class),
                new Reference(EventStoreInterface::class),
                new Reference(ActivityHeartbeatSenderInterface::class),
            ])
            ->setPublic(true)
        ;

        // The registry exists as soon as Temporal is configured, even with no handler declared: it
        // is its presence that NexusHandlerPass reads to know whether this backend can route.
        // Without it, the pass refuses — and that is the startup refusal §5.3 asks for.
        $container->register('durable.temporal.nexus_registry', NexusOperationRegistry::class)
            ->setFactory([NexusOperationRegistry::class, 'routedBy'])
            ->setArguments(['temporal'])
            ->setPublic(false)
        ;

        $container->register('durable.temporal.nexus_worker', TemporalNexusWorker::class)
            ->setArguments([
                new Reference(WorkflowServiceNexusRpc::class),
                new Reference('durable.temporal.connection'),
                new Reference('durable.temporal.nexus_registry'),
            ])
            ->setPublic(true)
        ;

        // The workers are consumed by alias (`messenger:consume durable_workflows`), with no
        // transport in the application's messenger.yaml: one server, one DSN, and the bundle knows
        // which loop each name runs. NexusHandlerPass tags the Nexus one once a handler exists.
        $container->register('durable.temporal.nexus_receiver', TemporalNexusWorkerTransport::class)
            ->setArguments([new Reference('durable.temporal.nexus_worker')])
        ;

        // Without the journal, workflows run locally and the application's own
        // durable_workflows / durable_activities transports carry them.
        if (!DurableExtension::isTemporalNative($config)) {
            return;
        }

        $container->register('durable.temporal.workflows_receiver', TemporalJournalTransport::class)
            ->setArguments([new Reference(WorkflowTaskProcessor::class)])
            ->addTag('messenger.receiver', ['alias' => 'durable_workflows'])
        ;

        $container->register('durable.temporal.activities_receiver', TemporalActivityWorkerTransport::class)
            ->setArguments([new Reference('durable.temporal.activity_worker')])
            ->addTag('messenger.receiver', ['alias' => 'durable_activities'])
        ;
    }
}
