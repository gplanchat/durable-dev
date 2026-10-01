<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Tests;

use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

final class TheRunListTest extends PanelTestCase
{
    private FakeCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new FakeCatalog([
            new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, new \DateTimeImmutable('2026-09-29 09:00:00'), waitingOn: 'payment-received', executionId: 'order-1'),
            new WorkflowRunDescription('run-2', 'App\\RefundWorkflow', WorkflowRunStatus::Failed, executionId: 'refund-7'),
        ]);
        $this->app->instance(WorkflowRunCatalogInterface::class, $this->catalog);
    }

    public function testItListsEachRunWithALinkToItsPage(): void
    {
        $this->get('/admin/durable/runs')
            ->assertOk()
            ->assertSee('App\\OrderWorkflow')
            ->assertSee('order-1')
            ->assertSee('waiting on payment-received')
            ->assertSee('App\\RefundWorkflow')
            ->assertSee('/admin/durable/run?executionId=refund-7', false)
            ->assertSee('The fake answers.');
    }

    public function testTheTableSpacesItsCellsAndKeepsBadgesAndDatesWhole(): void
    {
        // #850: unpadded cells glued Execution to Workflow, and the Outcome badges and the Started
        // date were squeezed into "Complet…" and two lines.
        $this->get('/admin/durable/runs')
            ->assertSee('class="durable-runs"', false)
            ->assertSee('.durable-runs th, .durable-runs td { padding:', false)
            ->assertSee('<td style="white-space: nowrap">2026-09-29 09:00:00</td>', false);
    }

    public function testTheCountersNameThePageTheyCover(): void
    {
        $this->get('/admin/durable/runs')->assertSee('Outcomes across the 2 runs on this page');
    }

    public function testItPagesForwardByTheCatalogCursorAndBackToTheFirstPage(): void
    {
        $this->catalog->nextCursor = 'cursor-2';

        $this->get('/admin/durable/runs')
            ->assertSee('cursor=cursor-2', false)
            ->assertDontSee('First page');

        $this->get('/admin/durable/runs?cursor=cursor-2')->assertSee('First page');
        self::assertSame('cursor-2', $this->catalog->askedCursor);
    }

    public function testItFiltersByWorkflowNameAndExecutionIdPrefix(): void
    {
        $this->get('/admin/durable/runs?workflowName=App%5COrderWorkflow&executionIdPrefix=order-')->assertOk();

        $filter = $this->catalog->askedFilter;
        self::assertNotNull($filter);
        self::assertSame('App\\OrderWorkflow', $filter->workflowName);
        self::assertSame('order-', $filter->executionIdPrefix);
    }

    public function testItFiltersByEachOutcome(): void
    {
        $this->catalog->runs = array_map(
            static fn(WorkflowRunStatus $status): WorkflowRunDescription => new WorkflowRunDescription('run-' . $status->value, 'App\\OrderWorkflow', $status, executionId: 'id-' . $status->value),
            WorkflowRunStatus::cases(),
        );

        foreach (WorkflowRunStatus::cases() as $asked) {
            $response = $this->get('/admin/durable/runs?status=' . $asked->value)->assertOk();
            self::assertSame($asked, $this->catalog->askedStatus);
            foreach (WorkflowRunStatus::cases() as $status) {
                $status === $asked
                    ? $response->assertSee('executionId=id-' . $status->value, false)
                    : $response->assertDontSee('executionId=id-' . $status->value, false);
            }
        }
    }

    public function testTheOutcomeFilterComposesWithTheOthersAndSurvivesPaging(): void
    {
        $this->catalog->nextCursor = 'cursor-2';

        $next = $this->get('/admin/durable/runs?status=failed&workflowName=App%5CRefundWorkflow')->assertSee('<option value="failed" selected', false)->getContent();
        self::assertSame(WorkflowRunStatus::Failed, $this->catalog->askedStatus);
        self::assertSame('App\\RefundWorkflow', $this->catalog->askedFilter?->workflowName);
        self::assertMatchesRegularExpression('/href="[^"]*cursor=cursor-2[^"]*"/', (string) $next);
        preg_match('/href="([^"]*cursor=cursor-2[^"]*)"/', (string) $next, $link);
        self::assertStringContainsString('status=failed', $link[1]);

        $first = (string) $this->get('/admin/durable/runs?status=failed&cursor=cursor-2')->getContent();
        self::assertSame(WorkflowRunStatus::Failed, $this->catalog->askedStatus);
        // The first page link: the list without a cursor, with the outcome kept.
        self::assertStringContainsString('/admin/durable/runs?status=failed"', $first);
    }

    public function testItOffersNoFilterTheCatalogCannotApply(): void
    {
        $this->get('/admin/durable/runs')->assertSee('name="workflowName"', false);

        $this->catalog->filterable = false;

        $this->get('/admin/durable/runs')->assertDontSee('name="workflowName"', false);
    }

    public function testAnUnreachableBackendIsSaidAndNoListIsShown(): void
    {
        $this->catalog->reachable = false;

        $this->get('/admin/durable/runs')
            ->assertOk()
            ->assertSee('The fake is down.')
            ->assertDontSee('order-1');
    }

    public function testTheListSpeaksFrench(): void
    {
        $this->app->setLocale('fr');

        $this->get('/admin/durable/runs')->assertSee('Issues des 2 exécutions de cette page');
    }
}
