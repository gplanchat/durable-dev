<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RegisterDurableMiddlewarePass;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\Messenger\WorkflowRunDispatchProfilerMiddleware;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Debug\NullWorkflowExecutionObserver;
use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * What watches a run: the profiler's trace and data collector when it is on, the null observer when it is off.
 *
 * Moved verbatim out of {@see DurableExtension} (#342), which calls these in its load() order.
 *
 * @internal
 */
final class Observability
{
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
    public static function registerNullObserver(ContainerBuilder $container): void
    {
        $container->register('durable.execution_observer.null', NullWorkflowExecutionObserver::class)
            ->setPublic(false)
        ;

        DurableExtension::aliasObserver($container, 'durable.execution_observer.null');
    }

    public static function registerProfiler(ContainerBuilder $container): void
    {
        $container->register('durable.execution_trace', DurableExecutionTrace::class)
            // `services_resetter` empties the trace between two Messenger messages. A Temporal
            // worker never triggers it; the trace bounds itself for that case (MAX_ENTRIES).
            ->addTag('kernel.reset', ['method' => 'reset'])
            ->setPublic(true)
        ;

        DurableExtension::aliasObserver($container, 'durable.execution_trace');

        $container->register('durable.messenger.middleware.workflow_run_dispatch_profiler', WorkflowRunDispatchProfilerMiddleware::class)
            ->setArguments([new Reference('durable.execution_trace')])
            // Above the lock: its measurements then include the wait the lock imposes.
            ->addTag(RegisterDurableMiddlewarePass::TAG, ['priority' => 100])
        ;

        $container->register('durable.data_collector', DurableDataCollector::class)
            ->setArguments([
                new Reference('durable.execution_trace'),
                new Reference(WorkflowMetadataStore::class),
                new Reference(EventStoreInterface::class),
                new Reference(PayloadRedactorInterface::class),
            ])
            ->setPublic(false)
            ->addTag('data_collector', [
                'template' => '@Durable/Collector/durable.html.twig',
                'id' => 'durable',
            ])
        ;
        $container->setAlias(DurableDataCollector::class, 'durable.data_collector')->setPublic(true);
    }
}
