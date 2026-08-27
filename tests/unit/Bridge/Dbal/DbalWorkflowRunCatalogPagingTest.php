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
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;

/**
 * Parcourir la liste : filtrer par issue, et tourner les pages sans rien perdre ni rien voir deux
 * fois.
 *
 * Le second point n'est pas théorique ici. `started_at` est stocké à la seconde, et une salve
 * d'exécutions démarrées dans la même seconde partage donc la même date : un curseur à décalage
 * ferait glisser la fenêtre à chaque insertion concurrente, et un tri sans départage rendrait
 * l'ordre arbitraire d'une page à l'autre. Ces tests créent délibérément leurs exécutions dans la
 * même seconde.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/specs/workflow-run-observation/spec.md
 */
final class DbalWorkflowRunCatalogPagingTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testFilteringByStatusReturnsOnlyMatchingRuns(): void
    {
        $this->startRun('exec-failed-1', 'App\\OrderWorkflow');
        $this->startRun('exec-failed-2', 'App\\OrderWorkflow');
        $this->startRun('exec-done', 'App\\OrderWorkflow');
        $this->startRun('exec-cancelled', 'App\\OrderWorkflow');
        $this->startRun('exec-running', 'App\\OrderWorkflow');

        $this->failRun('exec-failed-1');
        $this->failRun('exec-failed-2');
        $this->eventStore()->append(new ExecutionCompleted('exec-done', 'ok'));
        $this->eventStore()->append(new WorkflowExecutionCancelled('exec-cancelled', 'annulé'));

        $page = $this->catalog()->listRuns(WorkflowRunStatus::Failed);

        self::assertSame(['exec-failed-1', 'exec-failed-2'], $this->idsOf($page->runs));
        self::assertNull($page->nextCursor);
    }

    public function testFilteringLeavesTheOtherStatusesReachable(): void
    {
        $this->startRun('exec-done', 'App\\OrderWorkflow');
        $this->startRun('exec-running', 'App\\OrderWorkflow');
        $this->eventStore()->append(new ExecutionCompleted('exec-done', 'ok'));

        self::assertSame(['exec-running'], $this->idsOf($this->catalog()->listRuns(WorkflowRunStatus::Running)->runs));
        self::assertSame(['exec-done'], $this->idsOf($this->catalog()->listRuns(WorkflowRunStatus::Completed)->runs));
        self::assertSame([], $this->idsOf($this->catalog()->listRuns(WorkflowRunStatus::Cancelled)->runs));
    }

    public function testPagingReturnsEachRunOnceAndNoneTwice(): void
    {
        $expected = [];
        foreach (range(1, 7) as $n) {
            $id = \sprintf('exec-%02d', $n);
            $this->startRun($id, 'App\\OrderWorkflow');
            $expected[] = $id;
        }

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->catalog()->listRuns(null, $cursor, 3);
            $seen = [...$seen, ...$this->idsOf($page->runs)];
            $cursor = $page->nextCursor;
            ++$pages;
            self::assertLessThan(10, $pages, 'la pagination ne se termine pas');
        } while (null !== $cursor);

        self::assertSame($expected, $seen);
        self::assertSame(\count($seen), \count(array_unique($seen)));
        self::assertSame(3, $pages);
    }

    public function testTheLastPageAnnouncesNoSuccessor(): void
    {
        $this->startRun('exec-01', 'App\\OrderWorkflow');
        $this->startRun('exec-02', 'App\\OrderWorkflow');

        $page = $this->catalog()->listRuns(null, null, 2);

        self::assertSame(['exec-01', 'exec-02'], $this->idsOf($page->runs));
        self::assertNull($page->nextCursor, 'une page exactement pleine ne doit pas promettre une page vide');
    }

    public function testAFilteredListPagesOverTheFilteredSetOnly(): void
    {
        foreach (range(1, 5) as $n) {
            $this->startRun(\sprintf('exec-%02d', $n), 'App\\OrderWorkflow');
        }
        $this->failRun('exec-01');
        $this->failRun('exec-03');
        $this->failRun('exec-05');

        $first = $this->catalog()->listRuns(WorkflowRunStatus::Failed, null, 2);
        self::assertSame(['exec-01', 'exec-03'], $this->idsOf($first->runs));
        self::assertNotNull($first->nextCursor);

        $second = $this->catalog()->listRuns(WorkflowRunStatus::Failed, $first->nextCursor, 2);
        self::assertSame(['exec-05'], $this->idsOf($second->runs));
        self::assertNull($second->nextCursor);
    }

    private function failRun(string $executionId): void
    {
        $this->eventStore()->append(WorkflowExecutionFailed::unhandledDeclaredActivityFailure(
            $executionId,
            new \RuntimeException('boum'),
        ));
    }

    /**
     * Démarre une exécution **et fige sa date**.
     *
     * `recordStart()` horodate à `now`, et `started_at` est stocké à la seconde : une salve de cinq
     * exécutions tombe dans la même seconde presque toujours, et à cheval sur deux de temps en
     * temps. L'ordre attendu changeait alors sous les pieds du test — c'est arrivé en CI, pas ici.
     * Ces tests portent sur la pagination, pas sur l'horodatage : la date est donc dictée.
     */
    private function startRun(string $executionId, string $workflowType, int $startedAt = 1_700_000_000): void
    {
        $this->metadataStore()->save($executionId, $workflowType, []);

        $this->connection->update(
            'durable_workflow_runs',
            ['started_at' => (new \DateTimeImmutable('@' . $startedAt))->setTimezone(new \DateTimeZone('UTC'))],
            ['execution_id' => $executionId],
            ['started_at' => 'datetime_immutable'],
        );
    }

    private function metadataStore(): ProjectingWorkflowMetadataStore
    {
        return new ProjectingWorkflowMetadataStore(
            new DbalWorkflowMetadataStore($this->connection, $this->schema()),
            $this->projection(),
        );
    }

    private function eventStore(): ProjectingEventStore
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
     * @return list<string>
     */
    private function idsOf(array $runs): array
    {
        return array_map(static fn($run): string => $run->runId, $runs);
    }
}
