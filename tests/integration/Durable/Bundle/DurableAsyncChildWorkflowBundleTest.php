<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Bundle;

use Gplanchat\Durable\Bundle\DurableBundle;
use Gplanchat\Durable\ChildWorkflowRunner;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\WorkflowRunMessage;
use integration\Gplanchat\Durable\Support\Workflow\ChildBoomWorkflow;
use integration\Gplanchat\Durable\Support\Workflow\ChildMiniWorkflow;
use integration\Gplanchat\Durable\Support\Workflow\ParentOfAsyncChildWorkflow;
use integration\Gplanchat\Durable\Support\Workflow\ParentOfBoomWorkflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * @internal
 */
#[CoversClass(ChildWorkflowRunner::class)]
final class DurableAsyncChildWorkflowBundleTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DurableAsyncChildQueueTestKernel::class;
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
    public function parentCompletesWhenChildRunsInSeparateWorkflowMessage(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $registry = $container->get(\Gplanchat\Durable\WorkflowRegistry::class);
        $store = $container->get(EventStoreInterface::class);

        $registry->registerClass(ChildMiniWorkflow::class);
        $registry->registerClass(ParentOfAsyncChildWorkflow::class);

        $parentId = '01900000-0000-7000-8000-0000000000c1';
        $bus = $container->get(MessageBusInterface::class);
        /** @var InMemoryTransport $workflowTransport */
        $workflowTransport = $container->get('messenger.transport.workflow_jobs');

        $bus->dispatch(new WorkflowRunMessage($parentId, 'ParentOfAsyncChild', []));

        $this->flushWorkflowQueueUntilIdle(
            $bus,
            $workflowTransport,
            $store,
            $container->get(ActivityTransportInterface::class),
            $container->get(ExecutionRuntime::class),
            $container->get(ChildWorkflowRunner::class),
        );

        self::assertSame(28, $this->lastCompletedResult($store, $parentId));

        $childCompletedOnParent = false;
        foreach ($store->readStream($parentId) as $e) {
            if ($e instanceof ChildWorkflowCompleted && 28 === $e->result()) {
                $childCompletedOnParent = true;
            }
        }
        self::assertTrue($childCompletedOnParent);
    }

    #[Test]
    public function asyncChildFailureProjectsLastWorkflowExecutionFailedOntoParentJournal(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $registry = $container->get(\Gplanchat\Durable\WorkflowRegistry::class);
        $store = $container->get(EventStoreInterface::class);

        $registry->registerClass(ChildBoomWorkflow::class);
        $registry->registerClass(ParentOfBoomWorkflow::class);

        $parentId = '01900000-0000-7000-8000-0000000000c2';
        $bus = $container->get(MessageBusInterface::class);
        /** @var InMemoryTransport $workflowTransport */
        $workflowTransport = $container->get('messenger.transport.workflow_jobs');

        $bus->dispatch(new WorkflowRunMessage($parentId, 'ParentOfBoom', []));

        $this->flushWorkflowQueueUntilIdleIgnoringHandlerFailures(
            $bus,
            $workflowTransport,
            $store,
            $container->get(ActivityTransportInterface::class),
            $container->get(ExecutionRuntime::class),
            $container->get(ChildWorkflowRunner::class),
        );

        $failed = null;
        foreach ($store->readStream($parentId) as $e) {
            if ($e instanceof ChildWorkflowFailed) {
                $failed = $e;
            }
        }

        self::assertInstanceOf(ChildWorkflowFailed::class, $failed);
        self::assertSame(WorkflowExecutionFailed::KIND_WORKFLOW_HANDLER, $failed->workflowFailureKind());
        self::assertSame(\RuntimeException::class, $failed->workflowFailureClass());
        self::assertSame('child-boom', $failed->failureMessage());
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

        self::fail('Trop d’itérations sur la file workflow');
    }

    /**
     * Comme {@see flushWorkflowQueueUntilIdle} mais n’interrompt pas la boucle si le handler
     * relève une exception (ex. parent après échec enfant rejoué).
     */
    private function flushWorkflowQueueUntilIdleIgnoringHandlerFailures(
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
                try {
                    $bus->dispatch($envelope->with(new ReceivedStamp('workflow_jobs')));
                } catch (\Throwable) {
                    // ADR018: harness async — certains dispatches lèvent (ex. reprise parent) ; on acquitte l’enveloppe et on itère ; échec = assertion finale.
                }
                $workflowTransport->ack($envelope);
            }
        }

        self::fail('Trop d’itérations sur la file workflow');
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

final class DurableAsyncChildQueueTestKernel extends \Symfony\Component\HttpKernel\Kernel
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
        $loader->load(__DIR__.'/config/durable_distributed_async_child_queue.php');
    }
}
