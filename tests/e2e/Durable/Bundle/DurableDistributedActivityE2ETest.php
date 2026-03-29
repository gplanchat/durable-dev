<?php

declare(strict_types=1);

namespace e2e\Gplanchat\Durable\Bundle;

use Gplanchat\Durable\Bundle\DurableBundle;
use Gplanchat\Durable\ChildWorkflowRunner;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\WorkflowRunMessage;
use integration\Gplanchat\Durable\Bundle\Support\ActFlowWorkflow;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * @internal
 */
#[CoversNothing]
final class DurableDistributedActivityE2ETest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DurableWorkflowQueueTestKernel::class;
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
    public function singleActivityCompletesAfterQueuedResumeAndActivityDrain(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(\Gplanchat\Durable\ActivityExecutor::class)->register('echo', static fn (array $p) => $p['v'] ?? '');

        $container->get(\Gplanchat\Durable\WorkflowRegistry::class)->registerClass(ActFlowWorkflow::class);

        $executionId = '01900000-0000-7000-8000-0000000000b1';
        $bus = $container->get(MessageBusInterface::class);
        /** @var InMemoryTransport $workflowTransport */
        $workflowTransport = $container->get('messenger.transport.workflow_jobs');

        $bus->dispatch(new WorkflowRunMessage($executionId, 'ActFlow', []));

        $this->flushWorkflowQueueUntilIdle(
            $bus,
            $workflowTransport,
            $container->get(EventStoreInterface::class),
            $container->get(ActivityTransportInterface::class),
            $container->get(ExecutionRuntime::class),
            $container->get(ChildWorkflowRunner::class),
        );

        self::assertSame('queued-ok', $this->lastCompletedResult($container->get(EventStoreInterface::class), $executionId));
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

final class DurableWorkflowQueueTestKernel extends \Symfony\Component\HttpKernel\Kernel
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
        $loader->load(\dirname(__DIR__, 3).'/integration/Durable/Bundle/config/durable_distributed_workflow_queue.php');
    }
}
