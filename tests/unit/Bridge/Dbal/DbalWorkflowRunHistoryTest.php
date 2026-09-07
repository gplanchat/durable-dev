<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\TestCase;

/**
 * The history of an execution, as an operator reads it.
 *
 * The part that costs: only `ActivityScheduled` carries the activity's **name**; the completion
 * and the failure have nothing but its id. Rendering the id on those rows would give an unreadable
 * frieze (`Activity: 42f1dd58-…` instead of `Activity: SendWelcomeEmail`), which is exactly the
 * flaw the Temporal dashboard had already fixed on its own side.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/specs/workflow-run-observation/spec.md
 */
final class DbalWorkflowRunHistoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testEventsComeBackInRecordedOrderAndCarryTheirTime(): void
    {
        $store = $this->eventStore();
        $store->append(new ExecutionStarted('exec-1', []));
        $store->append(new ActivityScheduled('exec-1', 'act-1', 'SendWelcomeEmail', []));
        $store->append(new ActivityCompleted('exec-1', 'act-1', ['ok' => true]));
        $store->append(new ExecutionCompleted('exec-1', 'done'));

        $history = $this->catalog()->readHistory($this->describedRun('exec-1'));

        self::assertCount(4, $history);
        self::assertSame([1, 2, 3, 4], array_map(static fn($e): int => $e->sequence, $history));
        foreach ($history as $event) {
            self::assertInstanceOf(\DateTimeImmutable::class, $event->recordedAt);
        }
    }

    public function testActivitiesAndSignalsLandOnDistinctKinds(): void
    {
        $store = $this->eventStore();
        $store->append(new ExecutionStarted('exec-1', []));
        $store->append(new ActivityScheduled('exec-1', 'act-1', 'SendWelcomeEmail', []));
        $store->append(new WorkflowSignalReceived('exec-1', 'orderApproved', []));
        $store->append(new WorkflowUpdateHandled('exec-1', 'changeAddress', [], null));

        $kinds = array_map(static fn($e): string => $e->kind->value, $this->catalog()->readHistory($this->describedRun('exec-1')));

        self::assertSame(
            [
                WorkflowRunEventKind::Execution->value,
                WorkflowRunEventKind::Activity->value,
                WorkflowRunEventKind::Signal->value,
                WorkflowRunEventKind::Update->value,
            ],
            $kinds,
        );
    }

    public function testAnActivityIsLabelledWithItsNameEvenOnCompletion(): void
    {
        $store = $this->eventStore();
        $store->append(new ActivityScheduled('exec-1', 'act-1', 'SendWelcomeEmail', []));
        $store->append(new ActivityCompleted('exec-1', 'act-1', ['ok' => true]));

        $labels = array_map(static fn($e): string => $e->label, $this->catalog()->readHistory($this->describedRun('exec-1')));

        self::assertSame(['SendWelcomeEmail', 'SendWelcomeEmail'], $labels);
    }

    public function testACompletionWithoutItsSchedulingFallsBackToTheIdentifier(): void
    {
        // Truncated journal — a purge, a partial resume: the completion has only the id at hand.
        $this->eventStore()->append(new ActivityCompleted('exec-1', 'act-orphan', null));

        $history = $this->catalog()->readHistory($this->describedRun('exec-1'));

        self::assertSame('act-orphan', $history[0]->label);
    }

    public function testAnEventWithNoLaneInThisDashboardIsStillListed(): void
    {
        $store = $this->eventStore();
        $store->append(new ExecutionStarted('exec-1', []));
        $store->append(new SideEffectRecorded('exec-1', 'se-1', 'roll-42'));

        $history = $this->catalog()->readHistory($this->describedRun('exec-1'));

        self::assertCount(2, $history, 'an event with no lane must not vanish from the list');
        self::assertSame(WorkflowRunEventKind::Other, $history[1]->kind);
    }

    public function testAnUnknownRunHasAnEmptyHistoryRatherThanAnError(): void
    {
        self::assertSame([], $this->catalog()->readHistory($this->describedRun('jamais-vue')));
    }

    private function describedRun(string $runId): WorkflowRunDescription
    {
        return new WorkflowRunDescription($runId, 'App\\OrderWorkflow', WorkflowRunStatus::Running);
    }

    private function eventStore(): DbalEventStore
    {
        return new DbalEventStore($this->connection, $this->schema());
    }

    private function catalog(): DbalWorkflowRunCatalog
    {
        return new DbalWorkflowRunCatalog($this->connection, $this->schema());
    }

    private function schema(): DurableSchema
    {
        return new DurableSchema($this->connection);
    }
}
