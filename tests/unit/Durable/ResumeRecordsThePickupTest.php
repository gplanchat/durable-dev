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

#[AsWorkflow(name: 'test.nap')]
final class NapWorkflow
{
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        $env->sleep(3600);

        return 'awake';
    }
}

/**
 * The asynchronous path, the one a dispatched run takes: a worker consumes the resume message and
 * calls ExecutionEngine::resume(), which never appends ExecutionStarted. The pickup has to be
 * recorded where the worker takes the message, or a run parked on its timer reads "waiting for a
 * worker" until it ends (#447).
 */
final class ResumeRecordsThePickupTest extends TestCase
{
    public function testARunAWorkerResumedIsNoLongerWaitingForOneEvenAsleepOnATimer(): void
    {
        $journal = new InMemoryEventStore();
        $catalog = new InMemoryWorkflowRunCatalog($journal);
        $store = new ProjectingEventStore($journal, $catalog);
        $metadata = new ProjectingWorkflowMetadataStore(new InMemoryWorkflowMetadataStore(), $catalog);
        $registry = new WorkflowRegistry();
        $registry->registerClass(NapWorkflow::class);
        $metadata->save('exec-1', NapWorkflow::class, []);

        self::assertNotNull($catalog->listRuns()->runs[0]->waitingForWorkerSince, 'dispatched, not consumed yet');

        $engine = new ExecutionEngine($store, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true));
        $handler = new ResumeWorkflowHandler(
            $engine,
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
        );
        $handler(new ResumeWorkflowMessage('exec-1'));

        self::assertNull($catalog->listRuns()->runs[0]->waitingForWorkerSince, 'picked up, and now asleep on its timer');
    }
}
