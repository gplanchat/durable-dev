<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Integration\Bundle;

use Gplanchat\Durable\Bundle\DurableBundle;
use Gplanchat\Durable\ChildWorkflowRunner;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Tests\Integration\Bundle\Support\TimerFlowWorkflow;
use Gplanchat\Durable\Tests\Support\WorkflowTestMaxClock;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\WorkflowRunMessage;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * @internal
 */
#[CoversNothing]
final class DurableDistributedTimerE2ETest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DurableTimerWorkflowQueueTestKernel::class;
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        self::$class = null;
        self::$kernel = null;
        self::$booted = false;
        restore_exception_handler();
    }

    #[Test]
    public function timerCompletesAfterFireWorkflowTimersThenResumeUntilExecutionCompleted(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(\Gplanchat\Durable\WorkflowRegistry::class)->registerClass(TimerFlowWorkflow::class);

        $executionId = '01900000-0000-7000-8000-0000000000d1';
        $bus = $container->get(MessageBusInterface::class);
        /** @var InMemoryTransport $workflowTransport */
        $workflowTransport = $container->get('messenger.transport.workflow_jobs');
        $store = $container->get(EventStoreInterface::class);

        $bus->dispatch(new WorkflowRunMessage($executionId, 'TimerFlow', []));

        $this->processOneWorkflowBatch($bus, $workflowTransport);

        $scheduled = 0;
        $completed = 0;
        foreach ($store->readStream($executionId) as $e) {
            if ($e instanceof TimerScheduled) {
                ++$scheduled;
            }
            if ($e instanceof TimerCompleted) {
                ++$completed;
            }
        }
        self::assertSame(1, $scheduled, 'un timer doit être planifié avant la reprise');
        self::assertSame(0, $completed, 'le timer ne doit pas être complété avant FireWorkflowTimers');

        $bus->dispatch(new FireWorkflowTimersMessage($executionId));

        $this->flushWorkflowQueueUntilIdle(
            $bus,
            $workflowTransport,
            $store,
            $container->get(ActivityTransportInterface::class),
            $container->get(ExecutionRuntime::class),
            $container->get(ChildWorkflowRunner::class),
        );

        self::assertSame('after-timer', $this->lastCompletedResult($store, $executionId));
    }

    private function processOneWorkflowBatch(MessageBusInterface $bus, InMemoryTransport $workflowTransport): void
    {
        $batch = iterator_to_array($workflowTransport->get());
        self::assertNotEmpty($batch, 'un message workflow doit être en file après dispatch');
        foreach ($batch as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('workflow_jobs')));
            $workflowTransport->ack($envelope);
        }
    }

    private function flushWorkflowQueueUntilIdle(
        MessageBusInterface $bus,
        InMemoryTransport $workflowTransport,
        EventStoreInterface $eventStore,
        ActivityTransportInterface $activityTransport,
        ExecutionRuntime $runtime,
        ChildWorkflowRunner $childWorkflowRunner,
    ): void {
        for ($i = 0; $i < 30; ++$i) {
            $this->drainAllPendingActivities($eventStore, $activityTransport, $runtime, $childWorkflowRunner);

            $batch = iterator_to_array($workflowTransport->get());
            if ([] === $batch) {
                return;
            }

            foreach ($batch as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('workflow_jobs')));
                $workflowTransport->ack($envelope);
            }
        }

        self::fail('Trop d’itérations sur la file workflow (boucle ?)');
    }

    private function drainAllPendingActivities(
        EventStoreInterface $eventStore,
        ActivityTransportInterface $activityTransport,
        ExecutionRuntime $runtime,
        ChildWorkflowRunner $childWorkflowRunner,
    ): void {
        if (!$activityTransport instanceof InMemoryActivityTransport) {
            return;
        }

        while (!$activityTransport->isEmpty()) {
            $peek = $activityTransport->peek();
            if (null === $peek) {
                break;
            }
            $ctx = new ExecutionContext($peek->executionId, $eventStore, $activityTransport, $childWorkflowRunner);
            $runtime->drainActivityQueueOnce($ctx);
        }
    }

    private function lastCompletedResult(EventStoreInterface $store, string $executionId): mixed
    {
        $last = null;
        foreach ($store->readStream($executionId) as $e) {
            if ($e instanceof ExecutionCompleted) {
                $last = $e->result();
            }
        }

        return $last;
    }
}

final class DurableTimerWorkflowQueueTestKernel extends \Symfony\Component\HttpKernel\Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DurableBundle(),
        ];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
        $loader->load(__DIR__.'/config/durable_distributed_workflow_queue.php');
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if (!$container->hasDefinition(ExecutionRuntime::class)) {
                    return;
                }
                if (!$container->hasDefinition(WorkflowTestMaxClock::class)) {
                    $container->register(WorkflowTestMaxClock::class, WorkflowTestMaxClock::class)->setPublic(true);
                }
                $container->getDefinition(ExecutionRuntime::class)->replaceArgument(4, [
                    new Reference(WorkflowTestMaxClock::class),
                    '__invoke',
                ]);
            }
        });
    }
}
