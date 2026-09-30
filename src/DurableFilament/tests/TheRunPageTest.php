<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Tests;

use Gplanchat\Durable\Observation\NexusOperationState;
use Gplanchat\Durable\Observation\NexusOperationSummary;
use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

final class TheRunPageTest extends PanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(WorkflowRunCatalogInterface::class, new FakeCatalog(
            [new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, waitingOn: 'payment-received', executionId: 'order-1')],
            histories: ['order-1' => [
                new WorkflowRunEvent(1, new \DateTimeImmutable('2026-09-29 09:00:00'), WorkflowRunEventKind::Execution, 'Workflow started'),
                new WorkflowRunEvent(2, new \DateTimeImmutable('2026-09-29 09:00:01'), WorkflowRunEventKind::Activity, 'chargeCard scheduled', ['amount' => 42], actionKey: 'activity:1'),
            ]],
        ));
    }

    public function testItShowsTheStatusTheWaitAndTheHistory(): void
    {
        $this->get('/admin/durable/run?executionId=order-1')
            ->assertOk()
            ->assertSee('App\\OrderWorkflow')
            ->assertSee('Running')
            ->assertSee('waiting on payment-received')
            ->assertSee('Workflow started')
            ->assertSee('chargeCard scheduled')
            ->assertSee('&quot;amount&quot;: 42', false);
    }

    public function testAnUnknownRunIsSaidAsSuch(): void
    {
        $this->get('/admin/durable/run?executionId=nope')
            ->assertOk()
            ->assertSee('No run with this id on this backend.');
    }

    public function testTheRunPageShowsTheNexusOperationsTheCatalogReports(): void
    {
        $catalog = $this->app->make(WorkflowRunCatalogInterface::class);
        self::assertInstanceOf(FakeCatalog::class, $catalog);
        $catalog->nexusOperations = [new NexusOperationSummary('stock-endpoint', 'stock', 'reserve', NexusOperationState::InFlight)];

        $this->get('/admin/durable/run?executionId=order-1')
            ->assertSee('Nexus operations')
            ->assertSee('stock-endpoint')
            ->assertSee('in flight');
    }

    public function testNoNexusOperationFromTheCatalogMeansNoNexusSection(): void
    {
        $this->get('/admin/durable/run?executionId=order-1')->assertOk()->assertDontSee('Nexus operations');
    }

    public function testTheNexusOperationsReadWhereTheyAreServedAndWhetherTheyAreSettled(): void
    {
        $html = view('durable-filament::nexus-operations', ['operations' => [
            new NexusOperationSummary('stock-endpoint', 'stock', 'reserve', NexusOperationState::InFlight),
            new NexusOperationSummary('billing-endpoint', 'billing', 'charge', NexusOperationState::TimedOut),
        ]])->render();

        self::assertStringContainsString('stock-endpoint', $html);
        self::assertStringContainsString('reserve', $html);
        self::assertStringContainsString('in flight', $html);
        self::assertStringContainsString('timed out', $html);
    }

    public function testTheNexusStatesSpeakFrench(): void
    {
        $this->app->setLocale('fr');

        $html = view('durable-filament::nexus-operations', ['operations' => [
            new NexusOperationSummary('stock-endpoint', 'stock', 'reserve', NexusOperationState::InFlight),
        ]])->render();

        self::assertStringContainsString('en cours', $html);
    }

    public function testNoNexusOperationMeansNoNexusSection(): void
    {
        self::assertSame('', trim(view('durable-filament::nexus-operations', ['operations' => []])->render()));
    }

    public function testTheHistoryIsPlacedInTimeAndTellsAQueueFromWork(): void
    {
        $at = static fn(int $second): \DateTimeImmutable => new \DateTimeImmutable('2026-09-29 09:00:0' . $second);
        $this->app->instance(WorkflowRunCatalogInterface::class, new FakeCatalog(
            [new WorkflowRunDescription('run-2', 'App\\ShipWorkflow', WorkflowRunStatus::Completed, executionId: 'ship-1')],
            histories: ['ship-1' => [
                new WorkflowRunEvent(1, $at(0), WorkflowRunEventKind::Execution, 'Workflow started'),
                new WorkflowRunEvent(2, $at(1), WorkflowRunEventKind::Activity, 'pack scheduled', actionKey: 'activity:1'),
                new WorkflowRunEvent(3, $at(3), WorkflowRunEventKind::Activity, 'pack started', actionKey: 'activity:1', started: true),
                new WorkflowRunEvent(4, $at(4), WorkflowRunEventKind::Activity, 'pack completed', actionKey: 'activity:1'),
            ]],
        ));

        // Scheduled at 1 s of a 4 s run, picked up 2 s later: a quarter in, half the width, hatched.
        $this->get('/admin/durable/run?executionId=ship-1')
            ->assertOk()
            ->assertSee('durable-frieze-bar activity waiting', false)
            ->assertSee('left: 25.000%; width: 50.000%', false)
            ->assertSee('Hatched: waiting to be picked up');
    }

    public function testTheRunPageMasksWithTheApplicationRedactor(): void
    {
        $this->app->instance(PayloadRedactorInterface::class, new class implements PayloadRedactorInterface {
            public function redact(mixed $payload): mixed
            {
                return ['amount' => 'masked by the application'];
            }
        });

        $this->get('/admin/durable/run?executionId=order-1')
            ->assertSee('masked by the application')
            ->assertDontSee('&quot;amount&quot;: 42', false);
    }
}
