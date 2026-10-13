<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowClient;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Testing\ConformanceClientWorkflow;
use Gplanchat\Durable\Testing\ConformanceOtherClientWorkflow;
use Gplanchat\Durable\Testing\WorkflowClientPortConformanceTestCase;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * The in-memory client on a stack that runs the first pass of a workflow in the caller's process,
 * as the memory backend does (#603).
 */
final class InMemoryWorkflowClientConformanceTest extends WorkflowClientPortConformanceTestCase
{
    private InMemoryEventStore $events;
    private InMemoryWorkflowMetadataStore $rows;
    private InMemoryWorkflowRunCatalog $catalog;
    private InMemoryWorkflowClient $client;

    protected function setUp(): void
    {
        $this->events = new InMemoryEventStore();
        $this->rows = new InMemoryWorkflowMetadataStore();
        $this->catalog = new InMemoryWorkflowRunCatalog($this->events);
        $metadata = new ProjectingWorkflowMetadataStore($this->rows, $this->catalog);
        $registry = new WorkflowRegistry();
        $registry->registerClass(ConformanceClientWorkflow::class);
        $registry->registerClass(ConformanceOtherClientWorkflow::class);

        $dispatcher = new class implements WorkflowResumeDispatcher {
            public ?ResumeWorkflowHandler $handler = null;

            public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
            {
                ($this->handler)(new ResumeWorkflowMessage($executionId->toString(), $pendingUpdates));
            }

            public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void {}

            public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
            {
                ($this->handler)(new ResumeWorkflowMessage($executionId->toString()));
            }
        };
        $dispatcher->handler = new ResumeWorkflowHandler(
            new ExecutionEngine($this->events, new ExecutionRuntime($this->events, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true)),
            $registry,
            $metadata,
            $dispatcher,
            $this->events,
            new InMemoryChildWorkflowParentLinkStore(),
            new class implements WorkflowTimerDispatcher {
                public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void {}
            },
            new WorkflowDefinitionLoader(),
        );
        $this->client = new InMemoryWorkflowClient($metadata, $dispatcher);
    }

    protected function clientUnderTest(): WorkflowClientPort
    {
        return $this->client;
    }

    protected function catalogUnderTest(): WorkflowRunCatalogInterface
    {
        return $this->catalog;
    }

    protected function startedInputOf(ExecutionId $executionId): ?array
    {
        return $this->rows->get($executionId)['payload'] ?? null;
    }

    public function testAStartRunsTheWorkflowsFirstPassAndASecondStartRunsNothing(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceClientWorkflow::TYPE);
        $written = $this->events->countEventsInStream($id);

        self::assertGreaterThan(0, $written, 'The start only wrote a row: nothing ran the workflow.');

        try {
            $this->client->start($id, ConformanceClientWorkflow::TYPE);
            self::fail('A second start must fail.');
        } catch (WorkflowExecutionAlreadyStarted) {
            self::assertSame($written, $this->events->countEventsInStream($id));
        }
    }
}
