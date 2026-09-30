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
