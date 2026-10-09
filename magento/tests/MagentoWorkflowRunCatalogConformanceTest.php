<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\WorkflowRunCatalogConformanceTestCase;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Gplanchat\DurableModule\Store\MagentoWorkflowRunCatalog;
use Gplanchat\DurableModule\Store\MagentoWorkflowRunProjection;

/**
 * The shared catalogue cases against the real Magento adapter on the journal's database (#752).
 * Runs are written by the Magento projection, so the catalogue reads what production writes.
 *
 * The journal is the in-memory one: the Magento event store is a separate change (#748), and the
 * history cases only need a stream to read.
 */
final class MagentoWorkflowRunCatalogConformanceTest extends WorkflowRunCatalogConformanceTestCase
{
    private MagentoWorkflowRunProjection $runs;
    private InMemoryEventStore $events;
    private MagentoWorkflowRunCatalog $catalog;

    protected function setUp(): void
    {
        $adapter = JournalHarness::adapter();
        $schema = new JournalSchema($adapter);
        $this->runs = new MagentoWorkflowRunProjection($adapter, $schema);
        $this->events = new InMemoryEventStore();
        $this->catalog = new MagentoWorkflowRunCatalog($adapter, $schema, new JournalRunHistoryReader($this->events));
    }

    protected function catalogUnderTest(): WorkflowRunCatalogInterface
    {
        return $this->catalog;
    }

    protected function startRun(string $executionId, string $workflowType): void
    {
        $this->runs->recordStart(ExecutionId::fromString($executionId), $workflowType);
        $this->events->append(new ExecutionStarted(ExecutionId::fromString($executionId), []));
    }

    protected function endRun(string $executionId, WorkflowRunStatus $outcome): void
    {
        $this->events->append(new ExecutionCompleted(ExecutionId::fromString($executionId), 'ok'));
        $this->runs->recordOutcome(ExecutionId::fromString($executionId), $outcome);
    }

    protected function canTellAPickup(): bool
    {
        return true;
    }

    protected function pickUp(string $executionId): void
    {
        $this->runs->recordPickup(ExecutionId::fromString($executionId));
    }

    protected function canTellAWait(): bool
    {
        return true;
    }

    protected function recordWait(string $executionId, ?string $waitingOn): void
    {
        $this->runs->recordWait(ExecutionId::fromString($executionId), $waitingOn);
    }
}
