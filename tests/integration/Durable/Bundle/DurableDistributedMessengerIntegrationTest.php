<?php

declare(strict_types=1);

namespace integration\Durable\Bundle;

use Gplanchat\Durable\Bundle\DurableBundle;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use integration\Durable\Bundle\Support\OrderWaitWorkflow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Distributed mode + **sync** Messenger transport: no infinite loop while waiting for a signal,
 * completion once the control messages have been delivered.
 *
 * @internal
 */
#[CoversClass(ResumeWorkflowHandler::class)]
#[CoversClass(DeliverWorkflowSignalHandler::class)]
final class DurableDistributedMessengerIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DurableDistributedTestKernel::class;
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
    public function workflowWaitsForSignalThenCompletesViaMessengerStack(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(\Gplanchat\Durable\WorkflowRegistry::class)->registerClass(OrderWaitWorkflow::class);

        $executionId = '01900000-0000-7000-8000-0000000000a1';
        $bus = $container->get(MessageBusInterface::class);
        $meta = $container->get(WorkflowMetadataStore::class);
        $store = $container->get(EventStoreInterface::class);

        // Starting is no longer a message: `ResumeWorkflowMessage` only ever *resumes*.
        // Starting goes through the dispatcher, which persists the metadata before publishing the
        // resume — which is also what the example application does.
        $container->get(WorkflowResumeDispatcher::class)->dispatchNewWorkflowRun($executionId, 'OrderWait', []);

        self::assertSame(false, $meta->get($executionId)['completed'] ?? null, 'workflow suspended');
        self::assertNull($this->lastExecutionCompletedResult($store, $executionId));

        $bus->dispatch(new DeliverWorkflowSignalMessage($executionId, 'approved', ['ref' => 'PO-9']));

        // The metadata no longer disappears on completion: DUR037 turned the observation of a run
        // into a projection, and a finished run remains a fact one can read. The marker replaces
        // the erasure.
        self::assertTrue($meta->get($executionId)['completed'] ?? false, 'workflow finished');
        self::assertSame(['ref' => 'PO-9'], $this->lastExecutionCompletedResult($store, $executionId));
    }

    private function lastExecutionCompletedResult(EventStoreInterface $store, string $executionId): mixed
    {
        $last = null;
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof ExecutionCompleted) {
                $last = $event->result();
            }
        }

        return $last;
    }
}

final class DurableDistributedTestKernel extends \Symfony\Component\HttpKernel\Kernel
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
        $loader->load(__DIR__ . '/config/durable_distributed_sync_messenger.php');
    }
}
