<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
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

/**
 * The asynchronous path records what the run waits on where it suspends, as it records the pickup
 * where a worker takes the message (#324, #447).
 */
final class ResumeRecordsTheWaitTest extends TestCase
{
    public function testTheResumeHandlerRecordsTheWaitAtEachSuspension(): void
    {
        $journal = new InMemoryEventStore();
        $catalog = new InMemoryWorkflowRunCatalog($journal);
        $store = new ProjectingEventStore($journal, $catalog);
        $metadata = new ProjectingWorkflowMetadataStore(new InMemoryWorkflowMetadataStore(), $catalog);
        $registry = new WorkflowRegistry();
        $registry->registerClass(GraceWorkflow::class);
        $metadata->save('exec', GraceWorkflow::class, []);

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

        self::assertStringStartsWith('timer "grace period" due at ', (string) $catalog->listRuns()->runs[0]->waitingOn);
    }
}
