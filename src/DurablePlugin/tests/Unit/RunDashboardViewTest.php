<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Plugin\Dashboard\RunDashboardView;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use PHPUnit\Framework\TestCase;

/**
 * Le modèle de vue du tableau de bord, bâti sur le port et sur rien d'autre.
 *
 * Deux exigences du spec se vérifient ici et nulle part ailleurs : un fait qu'un backend n'a pas
 * est **absent** du modèle, pas rendu en chaîne vide ; et sans backend lisible, la page le dit sans
 * nommer Temporal, qui peut n'avoir jamais été de la partie.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/specs/workflow-run-observation/spec.md
 */
final class RunDashboardViewTest extends TestCase
{
    public function testWithoutAReadableBackendThePageSaysSoWithoutNamingTemporal(): void
    {
        $view = (new RunDashboardView(null))->build();

        self::assertFalse($view['backend']['available']);
        self::assertNotSame('', $view['backend']['message']);
        self::assertStringNotContainsStringIgnoringCase('temporal', $view['backend']['message']);
        self::assertSame([], $view['runs']);
    }

    public function testRunsAreListedWithTheirNameAndOutcome(): void
    {
        $view = $this->viewOver([
            $this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Failed),
            $this->describedRun('run-2', 'App\\ReportWorkflow', WorkflowRunStatus::Running),
        ])->build();

        self::assertTrue($view['backend']['available']);
        self::assertSame(['run-1', 'run-2'], array_column($view['runs'], 'runId'));
        self::assertSame(['App\\OrderWorkflow', 'App\\ReportWorkflow'], array_column($view['runs'], 'workflowName'));
        self::assertSame(['failed', 'running'], array_column($view['runs'], 'status'));
    }

    public function testTheCountersAgreeWithWhatTheListShows(): void
    {
        $view = $this->viewOver([
            $this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Failed),
            $this->describedRun('run-2', 'App\\OrderWorkflow', WorkflowRunStatus::Failed),
            $this->describedRun('run-3', 'App\\OrderWorkflow', WorkflowRunStatus::Running),
            $this->describedRun('run-4', 'App\\OrderWorkflow', WorkflowRunStatus::Completed),
            $this->describedRun('run-5', 'App\\OrderWorkflow', WorkflowRunStatus::Cancelled),
        ])->build();

        self::assertSame(5, $view['kpis']['total']);
        self::assertSame(2, $view['kpis']['failed']);
        self::assertSame(1, $view['kpis']['running']);
        self::assertSame(1, $view['kpis']['completed']);
        self::assertSame(1, $view['kpis']['cancelled']);
        self::assertSame(\count($view['runs']), $view['kpis']['total']);
    }

    /**
     * Une issue sans compteur compte quand même dans le total : les compteurs cessent alors de
     * s'additionner, et c'est une application faite de workflows longs qui s'en aperçoit.
     */
    public function testEveryOutcomeHasItsOwnCounter(): void
    {
        $view = $this->viewOver([
            $this->describedRun('run-1', 'App\\ReportWorkflow', WorkflowRunStatus::ContinuedAsNew),
        ])->build();

        self::assertSame(1, $view['kpis']['continued_as_new']);
        self::assertSame(
            $view['kpis']['total'],
            array_sum(array_diff_key($view['kpis'], ['total' => null])),
            'la somme des issues doit faire le total',
        );
    }

    public function testAFactTheBackendDoesNotHaveIsAbsentAndNotEmpty(): void
    {
        $view = $this->viewOver([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)])->build();

        self::assertArrayNotHasKey('taskQueue', $view['runs'][0], 'une colonne vide ferait croire que l\'exécution n\'a pas de file');
        self::assertArrayNotHasKey('groupId', $view['runs'][0], 'DBAL n\'a pas de regroupement : absent, pas nul');
    }

    public function testAGroupingIdentifierIsCarriedWhenTheBackendHasOne(): void
    {
        $view = $this->viewOver([
            new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, null, null, 'wf-1'),
        ])->build();

        self::assertSame('wf-1', $view['runs'][0]['groupId']);
    }

    public function testTheStatusFilterAndTheCursorReachTheCatalog(): void
    {
        $catalog = new FakeRunCatalog([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Failed)], [], 'jeton-suivant');
        $view = (new RunDashboardView($catalog))->build('failed', 'jeton-courant');

        self::assertSame(WorkflowRunStatus::Failed, $catalog->askedStatus);
        self::assertSame('jeton-courant', $catalog->askedCursor);
        self::assertTrue($view['pagination']['hasNext']);
        self::assertSame('jeton-suivant', $view['pagination']['nextCursor']);
    }

    public function testAnUnknownStatusFilterIsIgnoredRatherThanRefused(): void
    {
        $catalog = new FakeRunCatalog([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)]);
        (new RunDashboardView($catalog))->build('sarcastique');

        self::assertNull($catalog->askedStatus);
    }

    public function testTheSelectedRunHistoryIsGroupedInDistinctLanes(): void
    {
        $catalog = new FakeRunCatalog(
            [$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)],
            [
                new WorkflowRunEvent(1, new \DateTimeImmutable('@1700000000'), WorkflowRunEventKind::Execution, 'Started'),
                new WorkflowRunEvent(2, new \DateTimeImmutable('@1700000010'), WorkflowRunEventKind::Activity, 'SendWelcomeEmail'),
                new WorkflowRunEvent(3, new \DateTimeImmutable('@1700000020'), WorkflowRunEventKind::Signal, 'orderApproved'),
            ],
        );

        $view = (new RunDashboardView($catalog))->build();

        self::assertSame('run-1', $view['selectedRun']['runId']);
        self::assertSame(['execution', 'activity', 'signal'], array_column($view['selectedRun']['lanes'], 'kind'));
        self::assertSame(['SendWelcomeEmail'], array_column($view['selectedRun']['lanes'][1]['events'], 'label'));
    }

    public function testALaneTheBackendNeverRecordsIsNotShownAtAll(): void
    {
        $catalog = new FakeRunCatalog(
            [$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)],
            [new WorkflowRunEvent(1, new \DateTimeImmutable('@1700000000'), WorkflowRunEventKind::Execution, 'Started')],
        );

        $view = (new RunDashboardView($catalog))->build();

        self::assertSame(['execution'], array_column($view['selectedRun']['lanes'], 'kind'));
    }

    /**
     * @param list<WorkflowRunDescription> $runs
     */
    private function viewOver(array $runs): RunDashboardView
    {
        return new RunDashboardView(new FakeRunCatalog($runs));
    }

    private function describedRun(string $runId, string $name, WorkflowRunStatus $status): WorkflowRunDescription
    {
        return new WorkflowRunDescription($runId, $name, $status);
    }
}

final class FakeRunCatalog implements WorkflowRunCatalogInterface
{
    public ?WorkflowRunStatus $askedStatus = null;
    public ?string $askedCursor = null;

    /**
     * @param list<WorkflowRunDescription> $runs
     * @param list<WorkflowRunEvent>       $history
     */
    public function __construct(
        private readonly array $runs = [],
        private readonly array $history = [],
        private readonly ?string $nextCursor = null,
    ) {}

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
    {
        $this->askedStatus = $status;
        $this->askedCursor = $cursor;

        return new WorkflowRunPage($this->runs, $this->nextCursor);
    }

    public function readHistory(WorkflowRunDescription $run): array
    {
        return $this->history;
    }
}
