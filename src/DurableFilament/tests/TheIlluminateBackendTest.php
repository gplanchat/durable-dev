<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Tests;

use Illuminate\Contracts\Console\Kernel;

final class TheIlluminateBackendTest extends PanelTestCase
{
    protected const BACKEND = 'illuminate';

    public function testTheIlluminateBackendListsAndShowsARun(): void
    {
        $this->app->make(Kernel::class)->call('migrate', ['--force' => true]);
        $this->startRun('sql-run-1');

        $this->get('/admin/durable/runs')->assertOk()->assertSee('sql-run-1')->assertSee('App\\ShipWorkflow')
            // Laravel's queue keeps no list of its workers.
            ->assertSee('Could not ask the backend whether a queue worker polls');
        $this->get('/admin/durable/run?executionId=sql-run-1')->assertOk()->assertSee('App\\ShipWorkflow');
    }

    public function testTheQueueRowReadsAsOneFrenchSentence(): void
    {
        $this->app->make(Kernel::class)->call('migrate', ['--force' => true]);
        $this->app->setLocale('fr');

        $this->get('/admin/durable/runs')->assertOk()
            ->assertSee('Impossible de demander au backend si un worker queue est à l’écoute : la file de Laravel ne tient aucune liste', false);
    }
}
