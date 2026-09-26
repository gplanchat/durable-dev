<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's view model, built on the port and on nothing else.
 *
 * Two requirements of the spec are checked here and nowhere else: a fact a backend does not have
 * is **absent** from the model, not rendered as an empty string; and with no readable backend, the
 * page says so without naming Temporal, which may never have been part of it.
 *
 * @see openspec/specs/workflow-run-observation/spec.md
 */
final class RunDashboardTest extends TestCase
{
    public function testWithoutAReadableBackendThePageSaysSoWithoutNamingTemporal(): void
    {
        $view = (new RunDashboard(null))->build();

        self::assertFalse($view['backend']['available']);
        self::assertNotSame('', $view['backend']['message']);
        self::assertStringNotContainsStringIgnoringCase('temporal', $view['backend']['message']);
        self::assertArrayNotHasKey('name', $view['backend'], 'with no backend, naming a server would send the reader down a false trail');
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
     * An outcome with no counter still counts in the total: the counters then stop adding up, and
     * it is an application made of long workflows that notices.
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
            'the sum of the outcomes must make the total',
        );
    }

    public function testAFactTheBackendDoesNotHaveIsAbsentAndNotEmpty(): void
    {
        $view = $this->viewOver([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)])->build();

        self::assertArrayNotHasKey('taskQueue', $view['runs'][0], 'an empty column would suggest the execution has no queue');
        self::assertArrayNotHasKey('groupId', $view['runs'][0], 'DBAL has no grouping: absent, not null');
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
        $catalog = new FakeRunCatalog([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Failed)], [], 'next-token');
        $view = (new RunDashboard($catalog))->build('failed', 'current-token');

        self::assertSame(WorkflowRunStatus::Failed, $catalog->askedStatus);
        self::assertSame('current-token', $catalog->askedCursor);
        self::assertTrue($view['pagination']['hasNext']);
        self::assertSame('next-token', $view['pagination']['nextCursor']);
    }

    public function testAnUnknownStatusFilterIsIgnoredRatherThanRefused(): void
    {
        $catalog = new FakeRunCatalog([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)]);
        (new RunDashboard($catalog))->build('sarcastic');

        self::assertNull($catalog->askedStatus);
    }

    /**
     * "A catalog is registered" and "the backend answers" are two distinct questions. A downed
     * database gave a serene, empty page, which is the worse of the two possible errors: the
     * operator concludes there is nothing to see.
     */
    public function testAnUnreachableBackendIsNotPresentedAsAnEmptyDashboard(): void
    {
        $catalog = new FakeRunCatalog(
            [$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)],
            [],
            null,
            reachable: false,
        );

        $view = (new RunDashboard($catalog))->build();

        self::assertFalse($view['backend']['available']);
        self::assertSame([], $view['runs'], 'listing nothing is better than listing the emptiness of a mute database');
        self::assertNull($catalog->askedCursor, 'no point asking a page of a backend that does not answer');
    }

    public function testAReachableBackendNamesItselfAndSaysWhenItWasChecked(): void
    {
        $view = $this->viewOver([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)])->build();

        self::assertTrue($view['backend']['available']);
        self::assertSame('Fake backend', $view['backend']['name']);
        self::assertInstanceOf(\DateTimeImmutable::class, $view['backend']['checkedAt']);
    }

    public function testTheSelectedRunHistoryIsGroupedIntoActions(): void
    {
        $catalog = new FakeRunCatalog(
            [$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)],
            [
                new WorkflowRunEvent(1, new \DateTimeImmutable('@1700000000'), WorkflowRunEventKind::Execution, 'Started'),
                new WorkflowRunEvent(2, new \DateTimeImmutable('@1700000010'), WorkflowRunEventKind::Activity, 'SendWelcomeEmail'),
                new WorkflowRunEvent(3, new \DateTimeImmutable('@1700000020'), WorkflowRunEventKind::Signal, 'orderApproved'),
            ],
        );

        $view = (new RunDashboard($catalog))->build();

        self::assertSame('run-1', $view['selectedRun']['runId']);
        self::assertSame(['execution', 'activity', 'signal'], self::kinds($view));
        self::assertSame(['SendWelcomeEmail'], self::labels($view, 1));
    }

    /**
     * A list page pays for no history nobody asked for (#264): `build()` reads the fallback run's.
     */
    public function testAListingReadsNoHistory(): void
    {
        $catalog = new FakeRunCatalog([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)], [], 'next-token');

        $view = (new RunDashboard($catalog))->listing('running', 'current-token');

        self::assertSame(0, $catalog->historyReads);
        self::assertArrayNotHasKey('selectedRun', $view);
        self::assertSame(['run-1'], array_column($view['runs'], 'runId'));
        self::assertSame(WorkflowRunStatus::Running, $catalog->askedStatus);
        self::assertSame('next-token', $view['pagination']['nextCursor']);
    }

    public function testARunIsReadByItsIdWithItsTimeline(): void
    {
        $catalog = new FakeRunCatalog(
            [$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running), $this->describedRun('run-2', 'App\\ReportWorkflow', WorkflowRunStatus::Completed)],
            [new WorkflowRunEvent(1, new \DateTimeImmutable('@1700000000'), WorkflowRunEventKind::Activity, 'SendWelcomeEmail')],
        );

        $view = (new RunDashboard($catalog))->run('run-2');

        self::assertTrue($view['backend']['available']);
        self::assertSame('run-2', $view['run']['runId'] ?? null);
        self::assertSame('completed', $view['run']['status']);
        self::assertArrayHasKey('timeline', $view['run']);
        self::assertSame(0, $catalog->listings, 'a run is found, not paged to');
    }

    public function testAnUnknownRunIsNoRunOnAnAnsweringBackend(): void
    {
        $view = $this->viewOver([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)])->run('run-nobody');

        self::assertTrue($view['backend']['available'], 'only here is an unknown id a not-found');
        self::assertNull($view['run']);
    }

    /**
     * A mute database is not a run that does not exist: the page says the backend is down.
     */
    public function testAMuteBackendIsNotAnUnknownRun(): void
    {
        $catalog = new FakeRunCatalog([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)], [], null, reachable: false);

        self::assertFalse((new RunDashboard($catalog))->run('run-1')['backend']['available']);
        self::assertSame(0, $catalog->finds, 'a backend that does not answer is not asked');
        self::assertFalse((new RunDashboard(null))->run('run-1')['backend']['available']);
    }

    public function testANexusOperationGetsItsOwnLineAndSaysWhereTheWaitHappens(): void
    {
        // A Nexus operation is the only point in an execution where the wait is served **elsewhere**.
        // Blended into the rest, it leaves an operator hunting for the failure in their own system
        // when it lies at somebody else's — hence its own row, and hence a label that names the
        // endpoint rather than the event type.
        $catalog = new FakeRunCatalog(
            [$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)],
            [
                new WorkflowRunEvent(1, new \DateTimeImmutable('@1700000000'), WorkflowRunEventKind::Execution, 'Started'),
                new WorkflowRunEvent(2, new \DateTimeImmutable('@1700000010'), WorkflowRunEventKind::Nexus, 'payments/billing/collect'),
            ],
        );

        $view = (new RunDashboard($catalog))->build();

        self::assertSame(['execution', 'nexus'], self::kinds($view));
        self::assertSame(['payments/billing/collect'], self::labels($view, 1));
    }

    public function testAnEventCarriesWhatTheBackendRecordedWithIt(): void
    {
        // The frieze answers "what". "With what" is the next question, every time: an activity's
        // call arguments, what it returned. Without that fact in the model, the template's
        // unfoldable would open onto nothing.
        $catalog = new FakeRunCatalog(
            [$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)],
            [
                new WorkflowRunEvent(
                    1,
                    new \DateTimeImmutable('@1700000000'),
                    WorkflowRunEventKind::Activity,
                    'SendWelcomeEmail',
                    ['payload' => ['customerId' => 'cus-42']],
                ),
            ],
        );

        $view = (new RunDashboard($catalog))->build();

        self::assertSame(
            ['payload' => ['customerId' => 'cus-42']],
            $view['selectedRun']['timeline']->actions[0]->events[0]->event->details,
        );
    }

    public function testAnEventWithNothingRecordedHasNoDetailsKeyAtAll(): void
    {
        // Same rule as the rest of the model: an absent fact is absent, not empty. That is what
        // lets the template leave a plain row rather than an unfoldable that opens onto
        // nothing.
        $catalog = new FakeRunCatalog(
            [$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)],
            [new WorkflowRunEvent(1, new \DateTimeImmutable('@1700000000'), WorkflowRunEventKind::Execution, 'Started')],
        );

        $view = (new RunDashboard($catalog))->build();

        self::assertSame([], $view['selectedRun']['timeline']->actions[0]->events[0]->event->details);
    }

    public function testWhatTheBackendNeverRecordsIsNotShownAtAll(): void
    {
        $catalog = new FakeRunCatalog(
            [$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)],
            [new WorkflowRunEvent(1, new \DateTimeImmutable('@1700000000'), WorkflowRunEventKind::Execution, 'Started')],
        );

        $view = (new RunDashboard($catalog))->build();

        self::assertSame(['execution'], self::kinds($view));
    }

    public function testAJournalThatCannotOutliveTheRequestIsAThirdStateAndNotAFailure(): void
    {
        // Neither "unreachable" — it answers — nor plain "reachable", under which an empty list
        // teaches the operator that no workflow has run, which is false.
        $view = (new RunDashboard(new FakeRunCatalog([], [], null, ephemeral: true)))->build();

        self::assertTrue($view['backend']['available']);
        self::assertTrue($view['backend']['ephemeral']);
    }

    public function testABackendThatOutlivesTheRequestSaysSoToo(): void
    {
        $view = $this->viewOver([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)])->build();

        self::assertFalse($view['backend']['ephemeral']);
    }

    /**
     * @param array{selectedRun: array<string, mixed>|null, ...} $view
     *
     * @return list<string>
     */
    private static function kinds(array $view): array
    {
        return array_map(
            static fn($action): string => $action->kind->value,
            $view['selectedRun']['timeline']->actions,
        );
    }

    /**
     * @param array{selectedRun: array<string, mixed>|null, ...} $view
     *
     * @return list<string>
     */
    private static function labels(array $view, int $action): array
    {
        return array_map(
            static fn($event): string => $event->event->label,
            $view['selectedRun']['timeline']->actions[$action]->events,
        );
    }

    public function testARunNobodyPickedUpSaysHowLongItHasWaitedForAWorker(): void
    {
        $dispatched = new \DateTimeImmutable('2026-09-24 10:00:00', new \DateTimeZone('UTC'));
        $catalog = new FakeRunCatalog([
            new WorkflowRunDescription('queued', 'App\\OrderWorkflow', WorkflowRunStatus::Running, $dispatched, waitingForWorkerSince: $dispatched),
            new WorkflowRunDescription('long', 'App\\OrderWorkflow', WorkflowRunStatus::Running, $dispatched, waitingForWorkerSince: $dispatched->modify('-2 hours')),
            $this->describedRun('sleeping', 'App\\OrderWorkflow', WorkflowRunStatus::Running),
        ], tellsWaitingForWorker: true);

        $view = (new RunDashboard($catalog, static fn(): \DateTimeImmutable => $dispatched->modify('+42 seconds')))->build();

        self::assertSame($dispatched, $view['runs'][0]['waitingForWorkerSince']);
        self::assertSame('waiting for a worker · 42 s', $view['runs'][0]['waitingForWorker']);
        self::assertSame('waiting for a worker · 2 h', $view['runs'][1]['waitingForWorker']);
        self::assertArrayNotHasKey('waitingForWorker', $view['runs'][2], 'a run waiting on a timer, or picked up, carries no such fact');
        self::assertSame(2, $view['waitingForWorkerOnThisPage'], 'counted over the page, like the outcome counters');
    }

    public function testASuspendedRunSaysWhatItWaitsOn(): void
    {
        $view = $this->viewOver([
            new WorkflowRunDescription('asleep', 'App\\OrderWorkflow', WorkflowRunStatus::Running, waitingOn: 'timer due at 2026-09-24T10:00:00+00:00'),
            $this->describedRun('unknown', 'App\\OrderWorkflow', WorkflowRunStatus::Running),
        ])->build();

        self::assertSame('waiting on timer due at 2026-09-24T10:00:00+00:00', $view['runs'][0]['waitingOn']);
        self::assertArrayNotHasKey('waitingOn', $view['runs'][1], 'a fact the backend does not keep is absent');
    }

    public function testABackendThatCannotTellCountsNoRunAsWaitingForAWorker(): void
    {
        $view = $this->viewOver([$this->describedRun('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running)])->build();

        // Zero would read as "no run waits": the backend does not know, so the page says nothing.
        self::assertArrayNotHasKey('waitingForWorkerOnThisPage', $view);
    }

    /**
     * @param list<WorkflowRunDescription> $runs
     */
    private function viewOver(array $runs): RunDashboard
    {
        return new RunDashboard(new FakeRunCatalog($runs));
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
    public int $historyReads = 0;
    public int $listings = 0;
    public int $finds = 0;

    /**
     * @param list<WorkflowRunDescription> $runs
     * @param list<WorkflowRunEvent>       $history
     */
    public function __construct(
        private readonly array $runs = [],
        private readonly array $history = [],
        private readonly ?string $nextCursor = null,
        private readonly bool $reachable = true,
        private readonly bool $ephemeral = false,
        private readonly bool $tellsWaitingForWorker = false,
    ) {}

    public function checkHealth(): BackendHealth
    {
        return new BackendHealth(
            'Fake backend',
            $this->reachable,
            $this->reachable ? 'The fake backend answers.' : 'The fake backend is unreachable.',
            new \DateTimeImmutable('@1700000000'),
            $this->ephemeral,
        );
    }

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
    {
        ++$this->listings;
        $this->askedStatus = $status;
        $this->askedCursor = $cursor;

        return new WorkflowRunPage($this->runs, $this->nextCursor, $this->tellsWaitingForWorker);
    }

    public function findRun(string $runId): ?WorkflowRunDescription
    {
        ++$this->finds;

        return array_values(array_filter($this->runs, static fn(WorkflowRunDescription $run): bool => $run->runId === $runId))[0] ?? null;
    }

    public function readHistory(WorkflowRunDescription $run): array
    {
        ++$this->historyReads;

        return $this->history;
    }
}
