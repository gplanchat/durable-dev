<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\Messenger\DeliverWorkflowSignalToTemporalHandler;
use Gplanchat\Bridge\Temporal\Messenger\DeliverWorkflowUpdateToTemporalHandler;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityHeartbeatSender;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Bundle\CacheWarmer\ActivityContractCacheWarmer;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\Handler\ActivityRunHandler;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler;
use Gplanchat\Durable\Bundle\Transport\MessengerWorkflowTimerDispatcher;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\LocalWorkflowBackend;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Port\WorkflowBackendInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Query\WorkflowQueryRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The engine's own services: the definition loader, the executors, the runtime, the parent-child coordinator, the contract resolver.
 *
 * Moved verbatim out of {@see DurableExtension} (#342), which calls these in its load() order.
 *
 * @internal
 */
final class CoreServices
{
    public static function registerWorkflowDefinitionLoader(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(WorkflowDefinitionLoader::class)) {
            return;
        }

        $container->register('durable.workflow_definition_loader', WorkflowDefinitionLoader::class)
            ->setPublic(false)
        ;
        $container->setAlias(WorkflowDefinitionLoader::class, 'durable.workflow_definition_loader')->setPublic(false);
    }

    public static function registerActivityExecutor(ContainerBuilder $container): void
    {
        $container->register('durable.activity_executor', RegistryActivityExecutor::class)
            ->setPublic(false)
        ;
        $container->setAlias(\Gplanchat\Durable\ActivityExecutor::class, 'durable.activity_executor')->setPublic(true);
    }

    public static function registerRuntime(ContainerBuilder $container): void
    {
        $container->register('durable.runtime', \Gplanchat\Durable\ExecutionRuntime::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(ActivityTransportInterface::class),
                new Reference(\Gplanchat\Durable\ActivityExecutor::class),
                '%durable.max_activity_retries%',
                null,
                true,
                new Reference(WorkflowExecutionObserverInterface::class),
            ])
            ->setPublic(false)
        ;
        $container->setAlias(\Gplanchat\Durable\ExecutionRuntime::class, 'durable.runtime')->setPublic(true);
    }

    public static function registerParentChildCoordinator(ContainerBuilder $container): void
    {
        $container->register('durable.parent_child_coordinator', ParentChildWorkflowCoordinator::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(WorkflowResumeDispatcher::class),
            ])
            ->setPublic(false)
        ;
        $container->setAlias(ParentChildWorkflowCoordinatorInterface::class, 'durable.parent_child_coordinator')->setPublic(true);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function registerActivityContractResolver(ContainerBuilder $container, array $config): void
    {
        $activityConfig = $config['activity_contracts'] ?? [];
        $cacheId = $activityConfig['cache'] ?? null;
        // No `hasDefinition()` here: an alias is not a definition — `Psr\Cache\CacheItemPoolInterface`
        // is one — and neither yet is a definition placed by an extension that runs after this one.
        // The check therefore answered false for perfectly valid configurations, and the requested
        // pool was discarded without a word. Referencing unconditionally hands the error to the
        // compiler, which knows how to say which service is missing.
        $cacheRef = null !== $cacheId ? new Reference($cacheId) : null;

        $container->register('durable.activity_contract_resolver', ActivityContractResolver::class)
            ->setArguments([$cacheRef])
            ->setPublic(false)
        ;
        $container->setAlias(ActivityContractResolver::class, 'durable.activity_contract_resolver')->setPublic(false);
    }

    public static function registerEngine(ContainerBuilder $container): void
    {
        $container->register('durable.uuid_generator', \Gplanchat\Durable\Uuid\NativeUuidV7Generator::class)
            ->setPublic(false);
        $container->setAlias(\Gplanchat\Durable\Uuid\NativeUuidV7Generator::class, 'durable.uuid_generator')->setPublic(false);
        $container->setAlias(\Gplanchat\Durable\Uuid\UuidGeneratorInterface::class, \Gplanchat\Durable\Uuid\NativeUuidV7Generator::class);

        $container->register('durable.engine', \Gplanchat\Durable\ExecutionEngine::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(\Gplanchat\Durable\ExecutionRuntime::class),
                new Reference(\Gplanchat\Durable\ChildWorkflowRunner::class),
                new Reference(ParentChildWorkflowCoordinatorInterface::class),
                new Reference(ActivityContractResolver::class),
                new Reference(WorkflowDefinitionLoader::class),
                new Reference(WorkflowExecutionObserverInterface::class),
                new Reference(\Gplanchat\Durable\Uuid\UuidGeneratorInterface::class),
            ])
            ->setPublic(false)
        ;
        $container->setAlias(\Gplanchat\Durable\ExecutionEngine::class, 'durable.engine')->setPublic(true);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function registerActivityContractCacheWarmer(ContainerBuilder $container, array $config): void
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

    public static function registerWorkflowQueryRunner(ContainerBuilder $container): void
    {
        // Public on purpose: application code runs its queries through it, so it is part of the
        // bundle's surface, not an internal the bundle could hide.
        $container->register('durable.query_runner', WorkflowQueryRunner::class)
            ->setArguments([new Reference(EventStoreInterface::class)])
            ->setPublic(false)
        ;
        $container->setAlias(WorkflowQueryRunner::class, 'durable.query_runner')->setPublic(true);
    }

    public static function registerWorkflowBackend(ContainerBuilder $container): void
    {
        // Public on purpose: the entry point an application starts and drives workflows through.
        $container->register('durable.workflow_backend', LocalWorkflowBackend::class)
            ->setArguments([new Reference(\Gplanchat\Durable\ExecutionEngine::class)])
            ->setPublic(false)
        ;
        $container->setAlias(WorkflowBackendInterface::class, 'durable.workflow_backend')->setPublic(true);
    }

    /**
     * On Temporal native the cluster is the journal: signals and updates go to it, and Temporal
     * fires the timers itself. The journal handlers would append to a local store nobody replays
     * and ask for a resume nothing performs (#333).
     *
     * @param array<string, mixed> $config
     */
    public static function registerWorkflowControlHandlers(ContainerBuilder $container, array $config): void
    {
        if (DurableExtension::isTemporalNative($config)) {
            $container->register('durable.handler.deliver_signal', DeliverWorkflowSignalToTemporalHandler::class)
                ->setArguments([new Reference(WorkflowClientInterface::class)])
                ->addTag('messenger.message_handler')
                ->setPublic(false);
            $container->setAlias(DeliverWorkflowSignalToTemporalHandler::class, 'durable.handler.deliver_signal')->setPublic(false);
            $container->register('durable.handler.deliver_update', DeliverWorkflowUpdateToTemporalHandler::class)
                ->setArguments([new Reference(WorkflowClientInterface::class)])
                ->addTag('messenger.message_handler')
                ->setPublic(false);
            $container->setAlias(DeliverWorkflowUpdateToTemporalHandler::class, 'durable.handler.deliver_update')->setPublic(false);

            return;
        }

        $container->register('durable.handler.deliver_signal', DeliverWorkflowSignalHandler::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(WorkflowResumeDispatcher::class),
            ])
            ->addTag('messenger.message_handler')
            ->setPublic(false);
        $container->setAlias(DeliverWorkflowSignalHandler::class, 'durable.handler.deliver_signal')->setPublic(false);

        $container->register('durable.handler.deliver_update', DeliverWorkflowUpdateHandler::class)
            ->setArguments([
                new Reference(WorkflowResumeDispatcher::class),
            ])
            ->addTag('messenger.message_handler')
            ->setPublic(false);
        $container->setAlias(DeliverWorkflowUpdateHandler::class, 'durable.handler.deliver_update')->setPublic(false);

        $container->register('durable.timer_dispatcher', MessengerWorkflowTimerDispatcher::class)
            ->setArguments([new Reference('messenger.default_bus')])
            ->setPublic(false);
        $container->setAlias(MessengerWorkflowTimerDispatcher::class, 'durable.timer_dispatcher')->setPublic(false);
        $container->setAlias(WorkflowTimerDispatcher::class, MessengerWorkflowTimerDispatcher::class);

        $container->register('durable.handler.fire_workflow_timers', FireWorkflowTimersHandler::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(\Gplanchat\Durable\ExecutionRuntime::class),
                new Reference(WorkflowResumeDispatcher::class),
                new Reference(WorkflowTimerDispatcher::class),
            ])
            ->addTag('messenger.message_handler')
            ->setPublic(false);
        $container->setAlias(FireWorkflowTimersHandler::class, 'durable.handler.fire_workflow_timers')->setPublic(false);
    }

    /**
     * What runs an activity message: the heartbeat sender, the processor, and its Messenger handler.
     *
     * @param array<string, mixed> $config
     */
    public static function registerActivityProcessor(ContainerBuilder $container, array $config, bool $isTemporalNative): void
    {
        if ($isTemporalNative) {
            $container->register('durable.activity_heartbeat_sender', TemporalActivityHeartbeatSender::class)
                ->setArguments([
                    new Reference(WorkflowServiceActivityRpc::class),
                    new Reference('durable.temporal.connection'),
                ])
                ->setPublic(false);
            $container->setAlias(TemporalActivityHeartbeatSender::class, 'durable.activity_heartbeat_sender')->setPublic(false);
            $container->setAlias(ActivityHeartbeatSenderInterface::class, TemporalActivityHeartbeatSender::class)->setPublic(false);
        } else {
            $container->register('durable.activity_heartbeat_sender', NullActivityHeartbeatSender::class)->setPublic(false);
            $container->setAlias(NullActivityHeartbeatSender::class, 'durable.activity_heartbeat_sender')->setPublic(false);
            $container->setAlias(ActivityHeartbeatSenderInterface::class, NullActivityHeartbeatSender::class)->setPublic(false);
        }

        $container->register('durable.activity_message_processor', ActivityMessageProcessor::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(ActivityTransportInterface::class),
                new Reference(\Gplanchat\Durable\ActivityExecutor::class),
                new Reference(WorkflowResumeDispatcher::class),
                new Reference(ActivityHeartbeatSenderInterface::class),
                '%durable.max_activity_retries%',
                new Reference(WorkflowExecutionObserverInterface::class),
            ])
            ->setPublic(false)
        ;
        $container->setAlias(ActivityMessageProcessor::class, 'durable.activity_message_processor')->setPublic(true);

        $activityTransportConfig = $config['activity_transport'] ?? [];
        if ('messenger' === ($activityTransportConfig['type'] ?? '')
            && !$isTemporalNative) {
            $activityTransportName = $activityTransportConfig['transport_name'] ?? 'durable_activities';
            $container->register('durable.handler.activity_run', ActivityRunHandler::class)
                ->setArguments([new Reference(ActivityMessageProcessor::class)])
                ->addTag('messenger.message_handler', ['from_transport' => $activityTransportName])
                ->setPublic(false)
            ;
            $container->setAlias(ActivityRunHandler::class, 'durable.handler.activity_run')->setPublic(true);
        }
    }
}
