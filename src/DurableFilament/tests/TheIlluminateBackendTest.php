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

        $this->get('/admin/durable/runs')->assertOk()->assertSee('sql-run-1')->assertSee('App\\ShipWorkflow');
        $this->get('/admin/durable/run?executionId=sql-run-1')->assertOk()->assertSee('App\\ShipWorkflow');
    }
}
