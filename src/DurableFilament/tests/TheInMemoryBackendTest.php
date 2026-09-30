<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Tests;

/**
 * The panel reads whatever catalog durable-laravel binds: nothing in the package names a backend.
 */
final class TheInMemoryBackendTest extends PanelTestCase
{
    public function testTheInMemoryBackendListsAndShowsARun(): void
    {
        $this->startRun('memory-run-1');

        $this->get('/admin/durable/runs')->assertOk()->assertSee('memory-run-1')->assertSee('App\\ShipWorkflow');
        $this->get('/admin/durable/run?executionId=memory-run-1')->assertOk()->assertSee('App\\ShipWorkflow');
    }
}
