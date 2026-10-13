<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowClient;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Testing\ConformanceClientWorkflow;
use Gplanchat\Durable\Testing\WorkflowClientPortConformanceTestCase;
use Gplanchat\Durable\WorkflowStartOptions;

final class InMemoryWorkflowClientConformanceTest extends WorkflowClientPortConformanceTestCase
{
    private InMemoryWorkflowRunCatalog $catalog;
    private InMemoryWorkflowClient $client;

    protected function setUp(): void
    {
        $this->catalog = new InMemoryWorkflowRunCatalog(new InMemoryEventStore());
        $this->client = new InMemoryWorkflowClient(new InMemoryWorkflowMetadataStore(), $this->catalog);
    }

    protected function clientUnderTest(): WorkflowClientPort
    {
        return $this->client;
    }

    protected function catalogUnderTest(): WorkflowRunCatalogInterface
    {
        return $this->catalog;
    }

    protected function endRun(ExecutionId $executionId, WorkflowRunStatus $outcome): void
    {
        $this->catalog->recordOutcome($executionId, $outcome);
    }

    public function testAStartOptionIsRefusedNotIgnored(): void
    {
        $this->expectException(UnsupportedByBackendException::class);
        $this->client->start(ExecutionId::fromString('exec-1'), ConformanceClientWorkflow::TYPE, [], WorkflowStartOptions::defaults());
    }

    public function testTheOperationsStillToBuildRefuseOnAKnownExecution(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->client->start($id, ConformanceClientWorkflow::TYPE);

        foreach ([fn() => $this->client->signal($id, 'pay'), fn() => $this->client->result($id, Duration::seconds(1))] as $call) {
            try {
                $call();
                self::fail('Expected a refusal.');
            } catch (UnsupportedByBackendException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
