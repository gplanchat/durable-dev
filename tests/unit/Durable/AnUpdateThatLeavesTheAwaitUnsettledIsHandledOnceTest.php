<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * A pending update is handed to the workflow once, whether or not its handler settles the
 * condition the workflow waits on. The pass used to take the same update again after journalling
 * it, and never left the loop.
 */
final class AnUpdateThatLeavesTheAwaitUnsettledIsHandledOnceTest extends TestCase
{
    public function testTheUpdateIsJournalledOnceAndThePassSuspends(): void
    {
        $store = new InMemoryEventStore();
        $metadata = new InMemoryWorkflowMetadataStore();
        $metadata->save(ExecutionId::fromString('exec-1'), CountingWorkflow::class, []);
        $registry = new WorkflowRegistry();
        $registry->registerClass(CountingWorkflow::class);

        (new ResumeWorkflowHandler(
            new ExecutionEngine($store, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $metadata,
            new NullWorkflowResumeDispatcher(),
            $store,
            new InMemoryChildWorkflowParentLinkStore(),
            new class implements WorkflowTimerDispatcher {
                public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void {}
            },
            new WorkflowDefinitionLoader(),
        ))(new ResumeWorkflowMessage('exec-1', [['name' => 'count', 'arguments' => [], 'updateId' => 'upd-1']]));

        $events = iterator_to_array($store->readStream(ExecutionId::fromString('exec-1')), false);
        $handled = array_filter($events, static fn(object $e): bool => $e instanceof WorkflowUpdateHandled);
        self::assertCount(1, $handled);
        self::assertEmpty(array_filter($events, static fn(object $e): bool => $e instanceof ExecutionCompleted), 'The pass must suspend: the condition is still unsettled.');
    }
}

#[AsWorkflow('test.counting')]
final class CountingWorkflow
{
    private bool $stop = false;
    private int $calls = 0;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsUpdateMethod('count')]
    public function count(): int
    {
        // A pass that hands this update out again would end the wait here, so that the test fails
        // on the count of journalled updates instead of looping.
        if (++$this->calls > 1) {
            $this->stop = true;
        }

        return 1;
    }

    #[AsWorkflowMethod]
    public function run(): string
    {
        $this->environment->await(fn(): bool => $this->stop);

        return 'done';
    }
}
