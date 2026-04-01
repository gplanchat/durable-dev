<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\TemporalJournalEventStore;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Bundle\CacheWarmer\ActivityContractCacheWarmer;
use Gplanchat\Durable\Bundle\Command\DiagnoseExecutionCommand;
use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\EventListener\ResetDurableProfilerListener;
use Gplanchat\Durable\Bundle\Handler\ActivityRunHandler;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler;
use Gplanchat\Durable\Bundle\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Bundle\Handler\WorkflowRunHandler;
use Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher;
use Gplanchat\Durable\Bundle\Messenger\WorkflowRunDispatchProfilerMiddleware;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\Port\LocalWorkflowBackend;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Port\WorkflowBackendInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Query\WorkflowQueryRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\DbalChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\DbalEventStore;
use Gplanchat\Durable\Store\DbalWorkflowMetadataStore;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\DbalActivityTransport;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

final class DurableExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('durable.max_activity_retries', $config['max_activity_retries'] ?? 0);

        $asyncChildMessenger = (bool) ($config['child_workflow']['async_messenger'] ?? false);
        $container->setParameter('durable.child_workflow_async_messenger', $asyncChildMessenger);

        $this->registerProfiler($container);
        $this->registerChildWorkflowParentLinkStore($container, $config);
        $this->registerEventStore($container, $config);
        $this->registerActivityTransport($container, $config);
        $this->registerActivityExecutor($container);
        $this->registerRuntime($container, $config);
        $this->registerWorkflowMessengerServices($container, $config);
        $this->registerParentChildCoordinator($container);
        $this->registerActivityContractResolver($container, $config);
        $this->registerEngine($container, $config);
        $this->registerActivityContractCacheWarmer($container, $config);
        $this->registerWorkflowControlHandlers($container);
        $this->registerWorkflowQueryRunner($container);
        $this->registerWorkflowBackend($container);
        $this->registerCommands($container, $config);

        $connectionName = (string) ($config['dbal_connection'] ?? 'default');
        $container->setAlias('durable.dbal.connection', 'doctrine.dbal.'.$connectionName.'_connection');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function durableDbalConnectionReference(array $config): Reference
    {
        $name = $config['dbal_connection'] ?? 'default';

        return new Reference('doctrine.dbal.'.$name.'_connection');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerChildWorkflowParentLinkStore(ContainerBuilder $container, array $config): void
    {
        $linkConfig = $config['child_workflow']['parent_link_store'] ?? [];
        $type = $linkConfig['type'] ?? 'in_memory';

        if ('dbal' === $type) {
            $container->register('durable.child_workflow_parent_link_store', DbalChildWorkflowParentLinkStore::class)
                ->setArguments([
                    $this->durableDbalConnectionReference($config),
                    $linkConfig['table_name'] ?? 'durable_child_workflow_parent_link',
                ])
                ->setPublic(true)
            ;
        } else {
            $container->register('durable.child_workflow_parent_link_store', InMemoryChildWorkflowParentLinkStore::class)
                ->setPublic(true)
            ;
        }

        $container->setAlias(ChildWorkflowParentLinkStoreInterface::class, 'durable.child_workflow_parent_link_store')
            ->setPublic(true)
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerEventStore(ContainerBuilder $container, array $config): void
    {
        $storeConfig = $config['event_store'] ?? [];
        $type = $storeConfig['type'] ?? 'in_memory';

        if ('dbal' === $type) {
            $container->register(EventStoreInterface::class, DbalEventStore::class)
                ->setArguments([
                    $this->durableDbalConnectionReference($config),
                    $storeConfig['table_name'] ?? 'durable_events',
                ])
                ->setPublic(true)
            ;

            return;
        }

        if ('temporal' === $type) {
            $temporalConfig = $storeConfig['temporal'] ?? [];
            $dsn = $temporalConfig['dsn'] ?? null;
            if (!\is_string($dsn) || '' === $dsn) {
                throw new \LogicException('Configuration "durable.event_store.temporal.dsn" is required and must be non-empty when durable.event_store.type is "temporal".');
            }

            $container->register('durable.temporal.connection', TemporalConnection::class)
                ->setFactory([TemporalConnection::class, 'fromDsn'])
                ->setArguments([$dsn])
            ;

            $container->register('durable.temporal.workflow_service_client', WorkflowServiceClient::class)
                ->setFactory([WorkflowServiceClientFactory::class, 'create'])
                ->setArguments([new Reference('durable.temporal.connection')])
            ;

            $container->register(EventStoreInterface::class, TemporalJournalEventStore::class)
                ->setArguments([
                    new Reference('durable.temporal.workflow_service_client'),
                    new Reference('durable.temporal.connection'),
                ])
                ->setPublic(true)
            ;

            return;
        }

        $container->register(EventStoreInterface::class, InMemoryEventStore::class)->setPublic(true);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerActivityTransport(ContainerBuilder $container, array $config): void
    {
        $transportConfig = $config['activity_transport'] ?? [];
        $type = $transportConfig['type'] ?? 'in_memory';

        if ('dbal' === $type) {
            $container->register(ActivityTransportInterface::class, DbalActivityTransport::class)
                ->setArguments([
                    $this->durableDbalConnectionReference($config),
                    $transportConfig['table_name'] ?? 'durable_activity_outbox',
                ])
                ->setPublic(true)
            ;
        } elseif ('messenger' === $type) {
            $transportName = $transportConfig['transport_name'] ?? 'durable_activities';
            $container->register(ActivityTransportInterface::class, MessengerActivityTransport::class)
                ->setArguments([
                    new Reference('messenger.transport.'.$transportName),
                    new Reference('messenger.transport.'.$transportName),
                ])
                ->setPublic(true)
            ;
        } else {
            $container->register(ActivityTransportInterface::class, InMemoryActivityTransport::class)->setPublic(true);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerRuntime(ContainerBuilder $container, array $config): void
    {
        $container->register(\Gplanchat\Durable\ExecutionRuntime::class, \Gplanchat\Durable\ExecutionRuntime::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(ActivityTransportInterface::class),
                new Reference(\Gplanchat\Durable\ActivityExecutor::class),
                '%durable.max_activity_retries%',
                null,
                true,
                new Reference(WorkflowExecutionObserverInterface::class),
            ])
            ->setPublic(true)
        ;
    }

    private function registerParentChildCoordinator(ContainerBuilder $container): void
    {
        $container->register(ParentChildWorkflowCoordinatorInterface::class, ParentChildWorkflowCoordinator::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(WorkflowResumeDispatcher::class),
            ])
            ->setPublic(true)
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerActivityContractResolver(ContainerBuilder $container, array $config): void
    {
        $activityConfig = $config['activity_contracts'] ?? [];
        $cacheId = $activityConfig['cache'] ?? null;
        $cacheRef = null !== $cacheId && $container->hasDefinition($cacheId)
            ? new Reference($cacheId)
            : null;

        $container->register(ActivityContractResolver::class, ActivityContractResolver::class)
            ->setArguments([$cacheRef])
            ->setPublic(false)
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerActivityContractCacheWarmer(ContainerBuilder $container, array $config): void
    {
        $activityConfig = $config['activity_contracts'] ?? [];
        $contractClasses = $activityConfig['contracts'] ?? [];
        if ([] === $contractClasses) {
            return;
        }

        $container->register('durable.activity_contract_cache_warmer', ActivityContractCacheWarmer::class)
            ->setArguments([
                new Reference(ActivityContractResolver::class),
                $contractClasses,
            ])
            ->addTag('kernel.cache_warmer')
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerEngine(ContainerBuilder $container, array $config): void
    {
        $container->register(\Gplanchat\Durable\ExecutionEngine::class, \Gplanchat\Durable\ExecutionEngine::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(\Gplanchat\Durable\ExecutionRuntime::class),
                new Reference(\Gplanchat\Durable\ChildWorkflowRunner::class),
                new Reference(ParentChildWorkflowCoordinatorInterface::class),
                new Reference(ActivityContractResolver::class),
                new Reference(WorkflowDefinitionLoader::class),
                new Reference(WorkflowExecutionObserverInterface::class),
            ])
            ->setPublic(true)
        ;
    }

    private function registerWorkflowControlHandlers(ContainerBuilder $container): void
    {
        $container->register(DeliverWorkflowSignalHandler::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(WorkflowResumeDispatcher::class),
            ])
            ->addTag('messenger.message_handler')
        ;

        $container->register(DeliverWorkflowUpdateHandler::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(WorkflowResumeDispatcher::class),
            ])
            ->addTag('messenger.message_handler')
        ;

        $container->register(FireWorkflowTimersHandler::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(\Gplanchat\Durable\ExecutionRuntime::class),
                new Reference(WorkflowResumeDispatcher::class),
            ])
            ->addTag('messenger.message_handler')
        ;
    }

    private function registerWorkflowQueryRunner(ContainerBuilder $container): void
    {
        $container->register(WorkflowQueryRunner::class)
            ->setArguments([new Reference(EventStoreInterface::class)])
            ->setPublic(true)
        ;
    }

    private function registerActivityExecutor(ContainerBuilder $container): void
    {
        $container->register(\Gplanchat\Durable\ActivityExecutor::class, RegistryActivityExecutor::class)
            ->setPublic(true)
        ;
    }

    private function registerWorkflowBackend(ContainerBuilder $container): void
    {
        $container->register(WorkflowBackendInterface::class, LocalWorkflowBackend::class)
            ->setArguments([new Reference(\Gplanchat\Durable\ExecutionEngine::class)])
            ->setPublic(true)
        ;
    }

    /**
     * Registre métadonnées workflow, {@see WorkflowRunHandler}, {@see WorkflowResumeDispatcher}, {@see ChildWorkflowRunner}, etc.
     * Le bundle suppose toujours l’exécution distribuée (Messenger) ; il n’existe pas de mode « non distribué » côté configuration.
     *
     * @param array<string, mixed> $config
     */
    private function registerWorkflowMessengerServices(ContainerBuilder $container, array $config): void
    {
        $metaConfig = $config['workflow_metadata'] ?? [];
        if ('dbal' === ($metaConfig['type'] ?? 'in_memory')) {
            $container->register(WorkflowMetadataStore::class, DbalWorkflowMetadataStore::class)
                ->setArguments([
                    $this->durableDbalConnectionReference($config),
                    $metaConfig['table_name'] ?? 'durable_workflow_metadata',
                ])
                ->setPublic(true)
            ;
        } else {
            $container->register(WorkflowMetadataStore::class, InMemoryWorkflowMetadataStore::class)
                ->setPublic(true)
            ;
        }

        $container->register(WorkflowDefinitionLoader::class, WorkflowDefinitionLoader::class)
            ->setPublic(false)
        ;

        $container->register(\Gplanchat\Durable\WorkflowRegistry::class, \Gplanchat\Durable\WorkflowRegistry::class)
            ->setArguments([new Reference(WorkflowDefinitionLoader::class)])
            ->setPublic(true)
        ;

        $container->register(\Gplanchat\Durable\ChildWorkflowRunner::class, \Gplanchat\Durable\ChildWorkflowRunner::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(\Gplanchat\Durable\ExecutionRuntime::class),
                new Reference(\Gplanchat\Durable\WorkflowRegistry::class),
                new Reference(\Gplanchat\Durable\ActivityExecutor::class),
                '%durable.max_activity_retries%',
                '%durable.child_workflow_async_messenger%',
                new Reference(WorkflowResumeDispatcher::class),
                new Reference(ChildWorkflowParentLinkStoreInterface::class),
            ])
            ->setPublic(true)
        ;

        $container->register(WorkflowResumeDispatcher::class, MessengerWorkflowResumeDispatcher::class)
            ->setArguments([new Reference('messenger.default_bus')])
            ->setPublic(true)
        ;

        $container->register(WorkflowRunHandler::class)
            ->setArguments([
                new Reference(\Gplanchat\Durable\ExecutionEngine::class),
                new Reference(\Gplanchat\Durable\WorkflowRegistry::class),
                new Reference(WorkflowMetadataStore::class),
                new Reference(WorkflowResumeDispatcher::class),
                new Reference(EventStoreInterface::class),
                new Reference(ChildWorkflowParentLinkStoreInterface::class),
                new Reference('messenger.default_bus'),
            ])
            ->addTag('messenger.message_handler')
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerCommands(ContainerBuilder $container, array $config): void
    {
        $container->register(ActivityMessageProcessor::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(ActivityTransportInterface::class),
                new Reference(\Gplanchat\Durable\ActivityExecutor::class),
                new Reference(WorkflowResumeDispatcher::class),
                '%durable.max_activity_retries%',
                new Reference(WorkflowExecutionObserverInterface::class),
            ])
            ->setPublic(true)
        ;

        $activityTransportConfig = $config['activity_transport'] ?? [];
        if ('messenger' === ($activityTransportConfig['type'] ?? '')) {
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
            ])
            ->addTag('console.command')
        ;
    }

    private function registerProfiler(ContainerBuilder $container): void
    {
        $container->register('durable.execution_trace', DurableExecutionTrace::class)
            ->setPublic(true)
        ;

        $container->setAlias(WorkflowExecutionObserverInterface::class, 'durable.execution_trace')
            ->setPublic(true)
        ;

        $container->register(ResetDurableProfilerListener::class)
            ->setArguments([new Reference('durable.execution_trace')])
            ->addTag('kernel.event_subscriber')
        ;

        $container->register('durable.messenger.middleware.workflow_run_dispatch_profiler', WorkflowRunDispatchProfilerMiddleware::class)
            ->setArguments([new Reference('durable.execution_trace')])
        ;

        $container->register(DurableDataCollector::class)
            ->setArguments([
                new Reference('durable.execution_trace'),
                new Reference(WorkflowMetadataStore::class),
                new Reference(EventStoreInterface::class),
            ])
            ->setPublic(true)
            ->addTag('data_collector', [
                'template' => '@Durable/Collector/durable.html.twig',
                'id' => 'durable',
            ])
        ;
    }
}
