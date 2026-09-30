<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowResumeDispatcher;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RegisterDurableMiddlewarePass;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\Messenger\EarlyResumeMiddleware;
use Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Bundle\Serializer\DurationNormalizer;
use Gplanchat\Durable\Bundle\Serializer\RetryLimitNormalizer;
use Gplanchat\Durable\Bundle\Serializer\TaskQueueNormalizer;
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
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * What runs Durable over Symfony Messenger: the activity transport, the resume and timer dispatchers and their handlers.
 *
 * Moved verbatim out of {@see DurableExtension} (#342), which calls these in its load() order.
 *
 * @internal
 */
final class MessengerServices
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $config
     */
    public static function registerActivityTransport(ContainerBuilder $container, array $config): void
    {
        $transportConfig = $config['activity_transport'] ?? [];
        $type = $transportConfig['type'] ?? 'in_memory';
        $isTemporalNative = DurableExtension::isTemporalNative($config);

        if ($isTemporalNative) {
            $container->register('durable.activity_transport', NoopActivityTransport::class)->setPublic(false);
            $container->setAlias(ActivityTransportInterface::class, 'durable.activity_transport');

            return;
        }

        if ('messenger' === $type) {
            $transportName = $transportConfig['transport_name'] ?? 'durable_activities';
            $container->register('durable.activity_transport', MessengerActivityTransport::class)
                ->setArguments([
                    new Reference('messenger.transport.' . $transportName),
                    new Reference('messenger.transport.' . $transportName),
                ])
                ->setPublic(false)
            ;
            $container->setAlias(ActivityTransportInterface::class, 'durable.activity_transport');

            return;
        }

        $container->register('durable.activity_transport', InMemoryActivityTransport::class)->setArguments([CoreServices::clock()])->setPublic(false);
        $container->setAlias(ActivityTransportInterface::class, 'durable.activity_transport');
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

        // Under an id, like the journal: the in-memory and DBAL paths put a decorator in front of it
        // and repoint the interface; Temporal keeps this alias (#342).
        $container->register('durable.workflow_metadata_store.inner', InMemoryWorkflowMetadataStore::class)
            ->setPublic(false)
        ;
        $container->setAlias(WorkflowMetadataStore::class, 'durable.workflow_metadata_store.inner')->setPublic(true);

        $container->register('durable.workflow_registry', \Gplanchat\Durable\WorkflowRegistry::class)
            ->setArguments([new Reference(WorkflowDefinitionLoader::class)])
            ->setPublic(false)
        ;
        $container->setAlias(\Gplanchat\Durable\WorkflowRegistry::class, 'durable.workflow_registry');

        $container->register('durable.child_workflow_runner', \Gplanchat\Durable\ChildWorkflowRunner::class)
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
            ->setPublic(false)
        ;
        $container->setAlias(\Gplanchat\Durable\ChildWorkflowRunner::class, 'durable.child_workflow_runner');

        if ($isTemporalNative) {
            $container->register('durable.resume_dispatcher', TemporalWorkflowResumeDispatcher::class)
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
                ->setPublic(false)
            ;
            $container->setAlias(WorkflowResumeDispatcher::class, 'durable.resume_dispatcher')->setPublic(true);
        } else {
            $container->register('durable.resume_dispatcher', MessengerWorkflowResumeDispatcher::class)
                ->setArguments([
                    new Reference('messenger.default_bus'),
                    new Reference(WorkflowMetadataStore::class),
                    // To tell a `sync` resume route apart (DUR050).
                    new Reference('messenger.senders_locator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ])
                ->setPublic(false)
            ;
            $container->setAlias(WorkflowResumeDispatcher::class, 'durable.resume_dispatcher')->setPublic(true);

            $container->register('durable.handler.resume_workflow', ResumeWorkflowHandler::class)
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
                ->setPublic(false);
            $container->setAlias(ResumeWorkflowHandler::class, 'durable.handler.resume_workflow')->setPublic(false);

            // An early resume that waited long is acknowledged rather than sent to the failure
            // transport, where it read as a lost run (#606). Outside the resume lock (90).
            $container->register('durable.messenger.early_resume', EarlyResumeMiddleware::class)
                ->setArguments([new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)])
                ->addTag(RegisterDurableMiddlewarePass::TAG, ['priority' => 95])
                ->addTag('monolog.logger', ['channel' => 'messenger'])
                ->setPublic(false)
            ;
        }
    }

    /**
     * A transport set to `messenger.transport.symfony_serializer` carries every Durable message as
     * JSON; the ObjectNormalizer recurses without end on Duration and RetryLimit, and loses a
     * TaskQueue's name (#643). Only when
     * symfony/serializer is installed: without it, there is no serializer to join.
     */
    public static function registerSerializerNormalizers(ContainerBuilder $container): void
    {
        if (!interface_exists(NormalizerInterface::class)) {
            return;
        }
        $container->register('durable.serializer.duration_normalizer', DurationNormalizer::class)
            ->addTag('serializer.normalizer')
            ->setPublic(false);
        $container->register('durable.serializer.retry_limit_normalizer', RetryLimitNormalizer::class)
            ->addTag('serializer.normalizer')
            ->setPublic(false);
        $container->register('durable.serializer.task_queue_normalizer', TaskQueueNormalizer::class)
            ->addTag('serializer.normalizer')
            ->setPublic(false);
    }
}
