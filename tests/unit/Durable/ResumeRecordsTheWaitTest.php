<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Awaitable\Deferred;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

#[AsWorkflow(name: 'test.grace')]
final class GraceWorkflow
{
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        $env->sleep(3600, 'grace period');

        return 'done';
    }
}

#[AsWorkflow(name: 'test.unnamed-wait')]
final class UnnamedWaitWorkflow
{
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        // What a child workflow hands its parent: a wait the run list has no words for.
        $env->await((new Deferred())->awaitable());

        return 'done';
    }
}

/**
 * The asynchronous path records what the run waits on where it suspends, as it records the pickup
 * where a worker takes the message (#324, #447).
 */
final class ResumeRecordsTheWaitTest extends TestCase
{
    public function testTheResumeHandlerRecordsTheWaitAtEachSuspension(): void
    {
        $catalog = $this->resume(GraceWorkflow::class);

        self::assertStringStartsWith('timer "grace period" due at ', (string) $catalog->listRuns()->runs[0]->waitingOn);
    }

    public function testAWaitWithoutWordsClearsThePreviousOneRatherThanLeavingItStale(): void
    {
        $catalog = $this->resume(UnnamedWaitWorkflow::class, 'activity charge attempt 1 in flight');

        self::assertNull($catalog->listRuns()->runs[0]->waitingOn);
    }

    /**
     * @param class-string $workflowClass
     */
    private function resume(string $workflowClass, ?string $previousWait = null): InMemoryWorkflowRunCatalog
    {
        $journal = new InMemoryEventStore();
        $catalog = new InMemoryWorkflowRunCatalog($journal);
        $store = new ProjectingEventStore($journal, $catalog);
        $metadata = new ProjectingWorkflowMetadataStore(new InMemoryWorkflowMetadataStore(), $catalog);
        $registry = new WorkflowRegistry();
        $registry->registerClass($workflowClass);
        $metadata->save('exec', $workflowClass, []);
        if (null !== $previousWait) {
            $catalog->recordWait('exec', $previousWait);
        }

        (new ResumeWorkflowHandler(
            new ExecutionEngine($store, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $metadata,
            new NullWorkflowResumeDispatcher(),
            $store,
            new InMemoryChildWorkflowParentLinkStore(),
            new class implements WorkflowTimerDispatcher {
                public function dispatchTimerFire(string $executionId, int $delayMs = 0): void {}
            },
            new WorkflowDefinitionLoader(),
            $catalog,
        ))(new ResumeWorkflowMessage('exec'));


        return $catalog;
    }
}
