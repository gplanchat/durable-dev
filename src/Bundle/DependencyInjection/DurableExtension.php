<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Bundle\CacheWarmer\ActivityContractCacheWarmer;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler;
use Gplanchat\Durable\Bundle\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Bundle\Handler\WorkflowRunHandler;
use Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\Port\LocalWorkflowBackend;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
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
use Gplanchat\Durable\Transport\MessengerActivityTransport;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class DurableExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('durable.max_activity_retries', $config['max_activity_retries'] ?? 0);

        $distributed = $config['distributed'] ?? false;
        $asyncChildMessenger = $distributed && ($config['child_workflow']['async_messenger'] ?? false);
        $container->setParameter('durable.child_workflow_async_messenger', $asyncChildMessenger);

        $this->registerChildWorkflowParentLinkStore($container, $config);
        $this->registerEventStore($container, $config);
        $this->registerActivityTransport($container, $config);
        $this->registerActivityExecutor($container);
        $this->registerRuntime($container, $config);
        $this->registerDistributedMode($container, $config);
        $this->registerParentChildCoordinator($container);
        $this->registerActivityContractResolver($container, $config);
        $this->registerEngine($container, $config);
        $this->registerActivityContractCacheWarmer($container, $config);
        $this->registerWorkflowControlHandlers($container);
        $this->registerWorkflowQueryRunner($container);
        $this->registerWorkflowBackend($container);
        $this->registerCommands($container);
    }

    private function registerChildWorkflowParentLinkStore(ContainerBuilder $container, array $config): void
    {
        $linkConfig = $config['child_workflow']['parent_link_store'] ?? [];
        $type = $linkConfig['type'] ?? 'in_memory';

        if ('dbal' === $type) {
            $container->register('durable.child_workflow_parent_link_store', DbalChildWorkflowParentLinkStore::class)
                ->setArguments([
                    new Reference('doctrine.dbal.default_connection'),
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

    private function registerEventStore(ContainerBuilder $container, array $config): void
    {
        $storeConfig = $config['event_store'] ?? [];
        if (($storeConfig['type'] ?? 'in_memory') === 'dbal') {
            $container->register(EventStoreInterface::class, DbalEventStore::class)
                ->setArguments([
                    new Reference('doctrine.dbal.default_connection'),
                    $storeConfig['table_name'] ?? 'durable_events',
                ])
                ->setPublic(true)
            ;
        } else {
            $container->register(EventStoreInterface::class, InMemoryEventStore::class)->setPublic(true);
        }
    }

    private function registerActivityTransport(ContainerBuilder $container, array $config): void
    {
        $transportConfig = $config['activity_transport'] ?? [];
        $type = $transportConfig['type'] ?? 'in_memory';

        if ('dbal' === $type) {
            $container->register(ActivityTransportInterface::class, DbalActivityTransport::class)
                ->setArguments([
                    new Reference('doctrine.dbal.default_connection'),
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

    private function registerRuntime(ContainerBuilder $container, array $config): void
    {
        $distributed = $config['distributed'] ?? false;
        $container->setParameter('durable.distributed', $distributed);

        $container->register(\Gplanchat\Durable\ExecutionRuntime::class, \Gplanchat\Durable\ExecutionRuntime::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(ActivityTransportInterface::class),
                new Reference(\Gplanchat\Durable\ActivityExecutor::class),
                '%durable.max_activity_retries%',
                null,
                '%durable.distributed%',
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

    private function registerDistributedMode(ContainerBuilder $container, array $config): void
    {
        $distributed = $config['distributed'] ?? false;

        $metaConfig = $config['workflow_metadata'] ?? [];
        if (('dbal' === ($metaConfig['type'] ?? 'in_memory')) && $distributed) {
            $container->register(WorkflowMetadataStore::class, DbalWorkflowMetadataStore::class)
                ->setArguments([
                    new Reference('doctrine.dbal.default_connection'),
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

        if ($distributed) {
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
                ])
                ->addTag('messenger.message_handler')
            ;
        } else {
            $container->register(WorkflowResumeDispatcher::class, NullWorkflowResumeDispatcher::class)
                ->setPublic(true)
            ;
        }
    }

    private function registerCommands(ContainerBuilder $container): void
    {
        $container->register(\Gplanchat\Durable\Bundle\Command\ActivityWorkerCommand::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(ActivityTransportInterface::class),
                new Reference(\Gplanchat\Durable\ActivityExecutor::class),
                '%durable.max_activity_retries%',
                new Reference(WorkflowResumeDispatcher::class),
            ])
            ->addTag('console.command')
        ;
    }
}
