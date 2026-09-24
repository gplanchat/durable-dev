<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
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

#[AsWorkflow(name: 'test.chaining')]
final class ChainingWorkflow
{
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env, int $n = 0): never
    {
        $env->continueAsNew(self::class, ['n' => $n + 1]);
    }
}

/**
 * A continue-as-new used to delete the old run's metadata and start the new run under a fresh id
 * that neither journal recorded: the history before the continuation was lost to the dashboard and
 * to `durable:execution:diagnose` (#322). Both runs now name each other.
 */
final class ContinueAsNewKeepsTheChainTest extends TestCase
{
    public function testTheRunsOfAChainNameEachOther(): void
    {
        $journal = new InMemoryEventStore();
        $catalog = new InMemoryWorkflowRunCatalog($journal);
        $store = new ProjectingEventStore($journal, $catalog);
        $metadata = new ProjectingWorkflowMetadataStore(new InMemoryWorkflowMetadataStore(), $catalog);
        $metadata->save('exec-old', ChainingWorkflow::class, ['n' => 0]);
        $handler = $this->handlerFor($store, $metadata, $catalog);

        $handler(new ResumeWorkflowMessage('exec-old'));
        $newId = $this->successorOf($store, 'exec-old');
        self::assertSame('exec-old', $this->predecessorOf($store, $newId));
        self::assertSame(['n' => 1], $metadata->get($newId)['payload'] ?? null);
        self::assertTrue($metadata->get('exec-old')['completed'] ?? false, 'Superseded, not deleted.');

        $runs = $this->runsById($catalog);
        self::assertSame(WorkflowRunStatus::ContinuedAsNew, $runs['exec-old']->status);
        self::assertNotNull($runs[$newId]->waitingForWorkerSince, 'Its start is written, but no worker took it yet.');

        $handler(new ResumeWorkflowMessage($newId));
        $thirdId = $this->successorOf($store, $newId);
        self::assertSame($newId, $this->predecessorOf($store, $thirdId));
        self::assertSame(['n' => 2], $metadata->get($thirdId)['payload'] ?? null);
        self::assertSame(WorkflowRunStatus::ContinuedAsNew, $this->runsById($catalog)[$newId]->status);
    }

    private function successorOf(ProjectingEventStore $store, string $executionId): string
    {
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof WorkflowContinuedAsNew) {
                self::assertNotNull($event->newExecutionId(), 'The old run does not say which run continues it.');

                return $event->newExecutionId();
            }
        }
        self::fail('The run did not continue as new.');
    }

    private function predecessorOf(ProjectingEventStore $store, string $executionId): mixed
    {
        $started = iterator_to_array($store->readStream($executionId), false)[0] ?? null;
        self::assertInstanceOf(ExecutionStarted::class, $started, 'The new run has no start in its journal.');

        return $started->payload()['continuedFromExecutionId'] ?? null;
    }

    /**
     * @return array<string, WorkflowRunDescription>
     */
    private function runsById(InMemoryWorkflowRunCatalog $catalog): array
    {
        return array_column($catalog->listRuns()->runs, null, 'runId');
    }

    private function handlerFor(ProjectingEventStore $store, ProjectingWorkflowMetadataStore $metadata, InMemoryWorkflowRunCatalog $catalog): ResumeWorkflowHandler
    {
        $registry = new WorkflowRegistry();
        $registry->registerClass(ChainingWorkflow::class);

        return new ResumeWorkflowHandler(
            new ExecutionEngine(
                $store,
                new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
            ),
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
    }
}
