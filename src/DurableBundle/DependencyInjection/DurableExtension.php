<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunProjection;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\Messenger\TemporalActivityWorkerTransport;
use Gplanchat\Bridge\Temporal\Messenger\TemporalJournalTransport;
use Gplanchat\Bridge\Temporal\Messenger\TemporalNexusWorkerTransport;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityHeartbeatSender;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Bundle\Command\DiagnoseExecutionCommand;
use Gplanchat\Durable\Bundle\Command\DurableWorkerCommand;
use Gplanchat\Durable\Bundle\Command\SetupCommand;
use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RegisterDurableMiddlewarePass;
use Gplanchat\Durable\Bundle\DependencyInjection\Loader\CoreServices;
use Gplanchat\Durable\Bundle\DependencyInjection\Loader\EventStores;
use Gplanchat\Durable\Bundle\DependencyInjection\Loader\MessengerServices;
use Gplanchat\Durable\Bundle\EventListener\RefuseResetOnInMemoryTransportListener;
use Gplanchat\Durable\Bundle\Handler\ActivityRunHandler;
use Gplanchat\Durable\Bundle\Messenger\DurableWorkerInspection;
use Gplanchat\Durable\Bundle\Messenger\WorkflowRunDispatchProfilerMiddleware;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Bundle\SchemaListener\DurableSchemaListener;
use Gplanchat\Durable\Debug\NullWorkflowExecutionObserver;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Observation\KeyPatternPayloadRedactor;
use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
// And not HttpKernel's, which is only a thin subclass of it — `@internal` since
// Symfony 7.1, deprecated in 8.1 — and only adds the leftovers of the annotated class cache.
// This one has existed since 6.4: the swap costs no supported version.
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

final class DurableExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration($configs, $container), $configs);

        $container->setParameter('durable.max_activity_retries', $config['max_activity_retries'] ?? 0);

        $asyncChildMessenger = (bool) ($config['child_workflow']['async_messenger'] ?? false);
        $container->setParameter('durable.child_workflow_async_messenger', $asyncChildMessenger);

        if ($config['profiler']['enabled']) {
            $this->registerProfiler($container);
        } else {
            $this->registerNullObserver($container);
        }
        EventStores::registerChildWorkflowParentLinkStore($container);
        CoreServices::registerWorkflowDefinitionLoader($container);
        EventStores::registerEventStore($container, $config);
        MessengerServices::registerActivityTransport($container, $config);
        CoreServices::registerActivityExecutor($container);
        CoreServices::registerRuntime($container);
        MessengerServices::registerWorkflowMessengerServices($container, $config);
        CoreServices::registerParentChildCoordinator($container);
        // The pass that installs the middleware runs well after the extensions; it reads this
        // choice back here rather than rediscovering it.
        $container->setParameter(
            RegisterDurableMiddlewarePass::BUSES_PARAMETER,
            $config['messenger']['buses'] ?? [],
        );

        CoreServices::registerActivityContractResolver($container, $config);
        CoreServices::registerEngine($container);
        CoreServices::registerActivityContractCacheWarmer($container, $config);
        CoreServices::registerWorkflowControlHandlers($container, $config);
        CoreServices::registerWorkflowQueryRunner($container);
        CoreServices::registerWorkflowBackend($container);
        $this->registerCommands($container, $config);
        $this->registerTemporalMirrorInfrastructure($container, $config);
        $this->registerDbalStores($container, $config);
        $this->registerInMemoryRunCatalog($container);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        // A synthetic container, an extension test for instance, does not have this parameter;
        // that does not make it production, hence the default to debug.
        return new Configuration(!$container->hasParameter('kernel.debug') || (bool) $container->getParameter('kernel.debug'));
    }

    /**
     * Replaces the in-memory stores with their SQL equivalents when `type: dbal` is asked for.
     *
     * Called last: the in-memory definitions are already in place, we overwrite them rather than
     * branch inside the three methods that register them.
     *
     * @param array<string, mixed> $config
     *
     * @see DUR030
     */
    private function registerDbalStores(ContainerBuilder $container, array $config): void
    {
        $eventStoreDbal = 'dbal' === ($config['event_store']['type'] ?? 'in_memory');
        $metadataDbal = 'dbal' === ($config['workflow_metadata']['type'] ?? 'in_memory');
        $parentLinkDbal = 'dbal' === ($config['child_workflow']['parent_link_store']['type'] ?? 'in_memory');

        if (!$eventStoreDbal && !$metadataDbal && !$parentLinkDbal) {
            return;
        }

        $connection = new Reference($config['dbal']['connection']);

        $container->register('durable.dbal.schema', DurableSchema::class)
            ->setArguments([
                $connection,
                $config['event_store']['table_name'],
                $config['workflow_metadata']['table_name'],
                $config['child_workflow']['parent_link_store']['table_name'],
            ])
            ->setArgument('$autoSetup', $config['dbal']['auto_setup'])
            ->setPublic(false)
        ;
        $schema = new Reference('durable.dbal.schema');

        $container->register(SetupCommand::class)
            ->setArguments([$schema])
            ->addTag('console.command')
        ;

        // Without this listener, `doctrine:migrations:diff` does not see the journal's tables and
        // generates their removal. Registered only when the ORM is there: the DBAL bridge works
        // without it, and an application that has only the DBAL has no schema to complete.
        if (class_exists(GenerateSchemaEventArgs::class)) {
            $container->register('durable.dbal.schema_listener', DurableSchemaListener::class)
                ->setArguments([$schema])
                ->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema'])
                ->setPublic(false)
            ;
        }

        if ($eventStoreDbal) {
            $container->register('durable.event_store.dbal', DbalEventStore::class)
                ->setArguments([$connection, $schema, $config['event_store']['table_name']])
                ->setPublic(false)
            ;
            $container->setAlias(EventStoreInterface::class, 'durable.event_store.dbal')->setPublic(true);

            // With no server to serialize the tasks of one execution, the lock is mandatory.
            $container->register('durable.dbal.single_resume_lock', SingleResumeLockMiddleware::class)
                ->setArguments([new Reference($config['dbal']['lock_factory']), $config['dbal']['lock_ttl']])
                ->addTag(RegisterDurableMiddlewarePass::TAG, ['priority' => 90])
                ->setPublic(false)
            ;
        }

        if ($metadataDbal) {
            $container->register('durable.workflow_metadata_store.inner', DbalWorkflowMetadataStore::class)
                ->setArguments([$connection, $schema, $config['workflow_metadata']['table_name']])
                ->setPublic(false)
            ;
            $container->setAlias(WorkflowMetadataStore::class, 'durable.workflow_metadata_store.inner')->setPublic(true);
        }

        if ($parentLinkDbal) {
            $container->register('durable.child_workflow_parent_link_store', DbalChildWorkflowParentLinkStore::class)
                ->setArguments([$connection, $schema, $config['child_workflow']['parent_link_store']['table_name']])
                ->setPublic(true)
            ;
        }

        // The projection is only worth it if the journal is in SQL: that is where the outcomes come
        // from. An in-memory journal would leave rows that never finish.
        if ($eventStoreDbal) {
            $this->registerDbalRunCatalog($container, $connection, $schema);
        }
    }

    /**
     * The DBAL catalog, and the two pens that feed it.
     *
     * The decorators are placed here rather than in the blocks that register the journal and the
     * metadata: the name comes from `save()`, the outcome from the journal, and the two must point
     * at the **same** projection. Separating them would have invited instantiating two of them.
     *
     * @see openspec/changes/backend-neutral-workflow-dashboard/design.md
     */
    private function registerDbalRunCatalog(ContainerBuilder $container, Reference $connection, Reference $schema): void
    {
        $container->register('durable.dbal.run_projection', DbalWorkflowRunProjection::class)
            ->setArguments([$connection, $schema])
            ->setPublic(false)
        ;
        $projection = new Reference('durable.dbal.run_projection');
        // The resume handler records the pickup where the worker takes the message (#447).
        $container->setAlias(WorkflowRunPickupProjectionInterface::class, 'durable.dbal.run_projection')->setPublic(false);

        $container->register('durable.event_store.dbal.projecting', ProjectingEventStore::class)
            ->setArguments([new Reference('durable.event_store.dbal'), $projection])
            ->setPublic(false)
        ;
        $container->setAlias(EventStoreInterface::class, 'durable.event_store.dbal.projecting')->setPublic(true);

        // The journal can be in SQL without the metadata being so: in that case the store already
        // in place — in-memory — becomes the inside of the decorator, rather than demanding a
        // configuration nothing forces anyone to give.
        if (!$container->hasDefinition('durable.workflow_metadata_store.inner')) {
            $container->setDefinition(
                'durable.workflow_metadata_store.inner',
                $container->getDefinition(WorkflowMetadataStore::class)->setPublic(false),
            );
            $container->removeDefinition(WorkflowMetadataStore::class);
        }

        $container->register('durable.workflow_metadata_store.projecting', ProjectingWorkflowMetadataStore::class)
            ->setArguments([new Reference('durable.workflow_metadata_store.inner'), $projection])
            ->setPublic(false)
        ;
        $container->setAlias(WorkflowMetadataStore::class, 'durable.workflow_metadata_store.projecting')->setPublic(true);

        $container->register('durable.run_catalog.dbal', DbalWorkflowRunCatalog::class)
            ->setArguments([$connection, $schema])
            ->setPublic(false)
        ;
        $container->setAlias(WorkflowRunCatalogInterface::class, 'durable.run_catalog.dbal')->setPublic(true);
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
    private function registerInMemoryRunCatalog(ContainerBuilder $container): void
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

        // The metadata store is registered under its interface, not under an id: it becomes the
        // inside of the decorator, and the interface points at the decorator.
        $container->setDefinition(
            'durable.workflow_metadata_store.inner',
            // Private: it is reached through the interface, which points at the decorator.
            $container->getDefinition(WorkflowMetadataStore::class)->setPublic(false),
        );
        $container->removeDefinition(WorkflowMetadataStore::class);

        $container->register('durable.workflow_metadata_store.in_memory.projecting', ProjectingWorkflowMetadataStore::class)
            ->setArguments([new Reference('durable.workflow_metadata_store.inner'), $catalog])
            ->setPublic(false)
        ;
        $container->setAlias(WorkflowMetadataStore::class, 'durable.workflow_metadata_store.in_memory.projecting')->setPublic(true);
    }

    /**
     * "Native" Temporal: the cluster **is** the journal.
     *
     * A DSN without a journal says something else — the cluster is reachable for whatever needs
     * it, and serving a Nexus operation needs it, but the source of truth stays the one
     * `event_store` names. The two cannot share the same flag: it is the flag that unplugs the
     * activity transport, the resume dispatcher and the dashboard's read aliases.
     *
     * @param array<string, mixed> $config
     *
     * @internal shared with the loaders under Loader/ (#342)
     */
    public static function isTemporalNative(array $config): bool
    {
        $dsn = $config['temporal']['dsn'] ?? null;

        return \is_string($dsn) && '' !== $dsn && false !== ($config['temporal']['journal'] ?? true);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerCommands(ContainerBuilder $container, array $config): void
    {
        // What the diagnose command and the profiler panel may show of a payload. An application
        // replaces it by aliasing the interface to its own implementation.
        $container->register('durable.payload_redactor', KeyPatternPayloadRedactor::class)->setPublic(false);
        // The application's services.yaml is loaded before this extension: keep its alias.
        if (!$container->hasAlias(PayloadRedactorInterface::class) && !$container->hasDefinition(PayloadRedactorInterface::class)) {
            $container->setAlias(PayloadRedactorInterface::class, 'durable.payload_redactor');
        }

        $isTemporalNative = self::isTemporalNative($config);

        if ($isTemporalNative) {
            $container->register(TemporalActivityHeartbeatSender::class)
                ->setArguments([
                    new Reference(WorkflowServiceActivityRpc::class),
                    new Reference('durable.temporal.connection'),
                ])
                ->setPublic(false);
            $container->setAlias(ActivityHeartbeatSenderInterface::class, TemporalActivityHeartbeatSender::class)->setPublic(false);
        } else {
            $container->register(NullActivityHeartbeatSender::class)->setPublic(false);
            $container->setAlias(ActivityHeartbeatSenderInterface::class, NullActivityHeartbeatSender::class)->setPublic(false);
        }

        $container->register(ActivityMessageProcessor::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(ActivityTransportInterface::class),
                new Reference(\Gplanchat\Durable\ActivityExecutor::class),
                new Reference(WorkflowResumeDispatcher::class),
                new Reference(ActivityHeartbeatSenderInterface::class),
                '%durable.max_activity_retries%',
                new Reference(WorkflowExecutionObserverInterface::class),
            ])
            ->setPublic(true)
        ;

        $activityTransportConfig = $config['activity_transport'] ?? [];
        if ('messenger' === ($activityTransportConfig['type'] ?? '')
            && !$isTemporalNative) {
            $activityTransportName = $activityTransportConfig['transport_name'] ?? 'durable_activities';
            $container->register(ActivityRunHandler::class)
                ->setArguments([new Reference(ActivityMessageProcessor::class)])
                ->addTag('messenger.message_handler', ['from_transport' => $activityTransportName])
                ->setPublic(true)
            ;
        }

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

    /**
     * The profiler is not neutral plumbing: its observer is injected into
     * `ExecutionRuntime`, `ExecutionEngine` and `ActivityMessageProcessor`, so it sits on the
     * hot path of every execution, and its trace is emptied only by a `kernel.request` listener,
     * which `messenger:consume` never fires.
     *
     * Outside debug, none of it is registered at all and observation falls back to a null object.
     * FrameworkBundle does the same for its own collectors, loaded from separate files under a
     * condition.
     */
    private function registerNullObserver(ContainerBuilder $container): void
    {
        $container->register('durable.execution_observer.null', NullWorkflowExecutionObserver::class)
            ->setPublic(false)
        ;

        self::aliasObserver($container, 'durable.execution_observer.null');
    }

    /**
     * Aliases the observation interface, **without overwriting what the application already
     * declared**.
     *
     * `UPGRADE.md` invites an application that wants to observe its executions in production to
     * implement the contract and alias the interface onto its own service. The definitions in the
     * application's `services.yaml` already exist when the extension loads, since
     * `MergeExtensionConfigurationPass` runs at compilation, after the configuration is loaded, so
     * an unconditional `setAlias()` erased that alias and the escape hatch did not work.
     *
     * @internal shared with the loaders under Loader/ (#342)
     */
    public static function aliasObserver(ContainerBuilder $container, string $service): void
    {
        if ($container->hasAlias(WorkflowExecutionObserverInterface::class)
            || $container->hasDefinition(WorkflowExecutionObserverInterface::class)
        ) {
            return;
        }

        $container->setAlias(WorkflowExecutionObserverInterface::class, $service)
            ->setPublic(true)
        ;
    }

    private function registerProfiler(ContainerBuilder $container): void
    {
        $container->register('durable.execution_trace', DurableExecutionTrace::class)
            // `services_resetter` empties the trace between two Messenger messages. A Temporal
            // worker never triggers it; the trace bounds itself for that case (MAX_ENTRIES).
            ->addTag('kernel.reset', ['method' => 'reset'])
            ->setPublic(true)
        ;

        self::aliasObserver($container, 'durable.execution_trace');

        $container->register('durable.messenger.middleware.workflow_run_dispatch_profiler', WorkflowRunDispatchProfilerMiddleware::class)
            ->setArguments([new Reference('durable.execution_trace')])
            // Above the lock: its measurements then include the wait the lock imposes.
            ->addTag(RegisterDurableMiddlewarePass::TAG, ['priority' => 100])
        ;

        $container->register(DurableDataCollector::class)
            ->setArguments([
                new Reference('durable.execution_trace'),
                new Reference(WorkflowMetadataStore::class),
                new Reference(EventStoreInterface::class),
                new Reference(PayloadRedactorInterface::class),
            ])
            ->setPublic(true)
            ->addTag('data_collector', [
                'template' => '@Durable/Collector/durable.html.twig',
                'id' => 'durable',
            ])
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerTemporalMirrorInfrastructure(ContainerBuilder $container, array $config): void
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
        if (!self::isTemporalNative($config)) {
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
