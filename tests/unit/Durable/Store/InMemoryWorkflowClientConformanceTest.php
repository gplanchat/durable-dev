<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowClient;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Testing\WorkflowClientPortConformanceTestCase;
use Gplanchat\Durable\Transport\AwaitedFact;

final class InMemoryWorkflowClientConformanceTest extends WorkflowClientPortConformanceTestCase
{
    private InMemoryWorkflowMetadataStore $rows;
    private InMemoryWorkflowRunCatalog $catalog;
    private InMemoryWorkflowClient $client;

    /** @var list<string> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->rows = new InMemoryWorkflowMetadataStore();
        $this->catalog = new InMemoryWorkflowRunCatalog(new InMemoryEventStore());
        $dispatched = &$this->dispatched;

        $this->client = new InMemoryWorkflowClient(
            new ProjectingWorkflowMetadataStore($this->rows, $this->catalog),
            new class ($dispatched) implements WorkflowResumeDispatcher {
                /** @param list<string> $dispatched */
                public function __construct(private array &$dispatched) {}

                public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void {}

                public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void {}

                public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
                {
                    $this->dispatched[] = $executionId->toString();
                }
            },
        );
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

    public function testAStartDispatchesTheRunOnceAndASecondStartDispatchesNothing(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, self::TYPE);

        try {
            $this->client->start($id, self::TYPE);
        } catch (\Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted) {
            // The second start is refused: that is the case under test.
        }

        self::assertSame(['exec-1'], $this->dispatched);
    }
}
