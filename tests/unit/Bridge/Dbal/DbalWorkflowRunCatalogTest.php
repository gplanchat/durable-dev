<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunProjection;
use Gplanchat\Bridge\Dbal\Store\ProjectingEventStore;
use Gplanchat\Bridge\Dbal\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use PHPUnit\Framework\TestCase;

/**
 * Une exécution qui finit mal doit rester descriptible.
 *
 * C'est la raison d'être du change : `ResumeWorkflowHandler` supprime la ligne de métadonnées sur
 * échec, annulation et continue-as-new, et `ExecutionStarted` ne porte pas le type de workflow —
 * une exécution en échec n'a donc de nom nulle part. Or un tableau de bord d'exploitation est
 * d'abord une liste d'échecs.
 *
 * Les scénarios couverts vivent dans
 * `openspec/changes/backend-neutral-workflow-dashboard/specs/workflow-run-observation/spec.md`,
 * sous « A run stays describable after it ends badly ».
 *
 * @see DUR030
 */
final class DbalWorkflowRunCatalogTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testAFailedRunIsListedWithItsName(): void
    {
        $this->startRun('exec-1', 'App\\OrderWorkflow');
        $this->eventStore()->append(WorkflowExecutionFailed::unhandledDeclaredActivityFailure(
            'exec-1',
            new \RuntimeException('le fournisseur a refusé la charge'),
        ));

        $runs = $this->catalog()->listRuns();

        self::assertCount(1, $runs);
        self::assertSame('exec-1', $runs[0]->runId);
        self::assertSame('App\\OrderWorkflow', $runs[0]->workflowName);
        self::assertSame(WorkflowRunStatus::Failed, $runs[0]->status);
    }

    public function testACancelledRunIsDistinguishableFromAFailedOne(): void
    {
        $this->startRun('exec-cancelled', 'App\\OrderWorkflow');
        $this->startRun('exec-failed', 'App\\OrderWorkflow');

        $this->eventStore()->append(new WorkflowExecutionCancelled('exec-cancelled', 'annulé par le client'));
        $this->eventStore()->append(WorkflowExecutionFailed::unhandledDeclaredActivityFailure(
            'exec-failed',
            new \RuntimeException('boum'),
        ));

        $byId = $this->indexById($this->catalog()->listRuns());

        self::assertSame(WorkflowRunStatus::Cancelled, $byId['exec-cancelled']->status);
        self::assertSame(WorkflowRunStatus::Failed, $byId['exec-failed']->status);
        self::assertSame('App\\OrderWorkflow', $byId['exec-cancelled']->workflowName);
    }

    public function testAContinuedAsNewRunLeavesBothRunsVisibleAndNeitherIsFailed(): void
    {
        $this->startRun('exec-first', 'App\\ReportWorkflow');
        $this->eventStore()->append(new WorkflowContinuedAsNew('exec-first', 'App\\ReportWorkflow', ['page' => 2]));
        // Le successeur naît d'un `save()` sous un nouvel id : le composant traite déjà
        // un continue-as-new comme une exécution neuve.
        $this->startRun('exec-second', 'App\\ReportWorkflow');

        $byId = $this->indexById($this->catalog()->listRuns());

        self::assertCount(2, $byId);
        self::assertSame(WorkflowRunStatus::ContinuedAsNew, $byId['exec-first']->status);
        self::assertNotSame(WorkflowRunStatus::Failed, $byId['exec-first']->status);
        self::assertSame(WorkflowRunStatus::Running, $byId['exec-second']->status);
    }

    private function startRun(string $executionId, string $workflowType): void
    {
        $this->metadataStore()->save($executionId, $workflowType, []);
    }

    private function metadataStore(): WorkflowMetadataStore
    {
        return new ProjectingWorkflowMetadataStore(
            new DbalWorkflowMetadataStore($this->connection, $this->schema()),
            $this->projection(),
        );
    }

    private function eventStore(): EventStoreInterface
    {
        return new ProjectingEventStore(
            new DbalEventStore($this->connection, $this->schema()),
            $this->projection(),
        );
    }

    private function catalog(): DbalWorkflowRunCatalog
    {
        return new DbalWorkflowRunCatalog($this->connection, $this->schema());
    }

    private function projection(): DbalWorkflowRunProjection
    {
        return new DbalWorkflowRunProjection($this->connection, $this->schema());
    }

    private function schema(): DurableSchema
    {
        return new DurableSchema($this->connection);
    }

    /**
     * @param list<\Gplanchat\Durable\Observation\WorkflowRunDescription> $runs
     *
     * @return array<string, \Gplanchat\Durable\Observation\WorkflowRunDescription>
     */
    private function indexById(array $runs): array
    {
        $byId = [];
        foreach ($runs as $run) {
            $byId[$run->runId] = $run;
        }

        return $byId;
    }
}
