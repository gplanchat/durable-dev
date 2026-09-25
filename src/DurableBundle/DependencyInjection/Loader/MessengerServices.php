<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowResumeDispatcher;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * What runs Durable over Symfony Messenger: the activity transport, the resume and timer dispatchers and their handlers.
 *
 * Moved verbatim out of {@see DurableExtension} (#342), which calls these in its load() order.
 *
 * @internal
 */
final class MessengerServices
{
    /**
     * @param array<string, mixed> $config
     */
    public static function registerActivityTransport(ContainerBuilder $container, array $config): void
    {
        $transportConfig = $config['activity_transport'] ?? [];
        $type = $transportConfig['type'] ?? 'in_memory';
        $isTemporalNative = DurableExtension::isTemporalNative($config);

        if ($isTemporalNative) {
            $container->register(ActivityTransportInterface::class, NoopActivityTransport::class)->setPublic(true);

            return;
        }

        if ('messenger' === $type) {
            $transportName = $transportConfig['transport_name'] ?? 'durable_activities';
            $container->register(ActivityTransportInterface::class, MessengerActivityTransport::class)
                ->setArguments([
                    new Reference('messenger.transport.' . $transportName),
                    new Reference('messenger.transport.' . $transportName),
                ])
                ->setPublic(true)
            ;

            return;
        }

        $container->register(ActivityTransportInterface::class, InMemoryActivityTransport::class)->setPublic(true);
    }

    /**
     * Workflow metadata registry, {@see ResumeWorkflowHandler}, {@see WorkflowResumeDispatcher}, {@see ChildWorkflowRunner}, etc.
     *
     * In native Temporal mode (`durable.temporal.dsn` not empty), the {@see WorkflowResumeDispatcher}
     * is {@see TemporalWorkflowResumeDispatcher}: it calls `WorkflowClient::startAsync()` (gRPC
     * `StartWorkflowExecution`) instead of dispatching a Messenger message, and its
     * `dispatchResume()` is a no-op (Temporal reschedules the next workflow task itself).
     *
     * In in-memory mode, {@see MessengerWorkflowResumeDispatcher} is registered and
     * {@see ResumeWorkflowHandler} handles the messages.
     *
     * @param array<string, mixed> $config
     */
    public static function registerWorkflowMessengerServices(ContainerBuilder $container, array $config): void
    {
        $isTemporalNative = DurableExtension::isTemporalNative($config);

        $container->register(WorkflowMetadataStore::class, InMemoryWorkflowMetadataStore::class)
            ->setPublic(true)
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

        if ($isTemporalNative) {
            $container->register(WorkflowResumeDispatcher::class, TemporalWorkflowResumeDispatcher::class)
                ->setArguments([
                    new Reference(WorkflowClientInterface::class),
                    new Reference(WorkflowMetadataStore::class),
                    new Reference(WorkflowDefinitionLoader::class),
                    // The profiler only exists in debug since this fix, and the target
                    // constructor declares the dependency `?DurableExecutionTrace $executionTrace = null`.
                    // A bare reference would fail the production container's compilation as soon as
                    // a `temporal.dsn` is configured.
                    new Reference('durable.execution_trace', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->setPublic(true)
            ;
        } else {
            $container->register(WorkflowResumeDispatcher::class, MessengerWorkflowResumeDispatcher::class)
                ->setArguments([
                    new Reference('messenger.default_bus'),
                    new Reference(WorkflowMetadataStore::class),
                ])
                ->setPublic(true)
            ;

            $container->register(ResumeWorkflowHandler::class)
                ->setArguments([
                    new Reference(\Gplanchat\Durable\ExecutionEngine::class),
                    new Reference(\Gplanchat\Durable\WorkflowRegistry::class),
                    new Reference(WorkflowMetadataStore::class),
                    new Reference(WorkflowResumeDispatcher::class),
                    new Reference(EventStoreInterface::class),
                    new Reference(ChildWorkflowParentLinkStoreInterface::class),
                    new Reference(WorkflowTimerDispatcher::class),
                    new Reference(WorkflowDefinitionLoader::class),
                    new Reference(WorkflowRunPickupProjectionInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->addTag('messenger.message_handler')
            ;
        }
    }
}
