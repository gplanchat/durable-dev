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
 * L'historique d'une exécution, tel qu'un exploitant le lit.
 *
 * Le point qui coûte : seul `ActivityScheduled` porte le **nom** de l'activité ; la complétion et
 * l'échec n'ont que son id. Rendre l'id sur ces lignes-là donnerait une frise illisible
 * (`Activity: 42f1dd58-…` au lieu de `Activity: SendWelcomeEmail`), ce qui est exactement le défaut
 * que le tableau de bord Temporal avait déjà corrigé de son côté.
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
        // Journal tronqué — purge, reprise partielle : la complétion n'a que l'id sous la main.
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

        self::assertCount(2, $history, 'un événement sans voie ne doit pas disparaître de la liste');
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
