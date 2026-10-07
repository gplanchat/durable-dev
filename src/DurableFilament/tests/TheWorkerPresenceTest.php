<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Tests;

use Gplanchat\Durable\Filament\WorkerPresence;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

final class TheWorkerPresenceTest extends PanelTestCase
{
    private FakeCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new FakeCatalog();
        $this->app->instance(WorkflowRunCatalogInterface::class, $this->catalog);
        $this->app->instance(WorkerPresence::class, new WorkerPresence(static fn(): array => [
            ['role' => 'workflow', 'lastPolledAt' => new \DateTimeImmutable('-30 seconds'), 'error' => null],
            // A stopped worker stays listed for minutes: its last poll is what gives it away.
            ['role' => 'activity', 'lastPolledAt' => new \DateTimeImmutable('-10 minutes'), 'error' => null],
            ['role' => 'queue', 'lastPolledAt' => null, 'error' => 'deadline exceeded'],
        ]));
    }

    public function testItNamesEachRolePolledWithoutPollerOrUnknown(): void
    {
        $this->get('/admin/durable/runs')
            ->assertOk()
            ->assertSee('The workflow worker is polling.')
            ->assertSee('No activity worker has polled in 120 seconds: executions stop at their first activity task.')
            ->assertSee('php artisan durable:temporal-worker --role=activity')
            ->assertSee('Could not ask the backend whether a queue worker polls: deadline exceeded');
    }

    public function testAnUnreachableBackendIsNotAskedWhoPolls(): void
    {
        $asked = false;
        $this->app->instance(WorkerPresence::class, new WorkerPresence(static function () use (&$asked): array {
            $asked = true;

            return [];
        }));
        $this->catalog->reachable = false;

        $this->get('/admin/durable/runs')->assertOk();
        self::assertFalse($asked);
    }

    public function testThePanelSpeaksFrench(): void
    {
        $this->app->setLocale('fr');

        $this->get('/admin/durable/runs')
            ->assertSee('Le worker workflow est à l’écoute.', false)
            ->assertSee('Personne à l’écoute', false)
            ->assertSee('Aucun worker activity n’a interrogé le backend depuis 120 secondes', false)
            ->assertSee('Impossible de demander au backend si un worker queue est à l’écoute : deadline exceeded', false);
    }
}
