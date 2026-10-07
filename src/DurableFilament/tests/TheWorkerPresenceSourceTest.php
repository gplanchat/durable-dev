<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Tests;

use Gplanchat\Bridge\Temporal\Store\TemporalTaskQueueProbe;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Filament\WorkerPresence;

/**
 * Which presence source the panel reads, from what durable-laravel binds.
 */
final class TheWorkerPresenceSourceTest extends PanelTestCase
{
    public function testAConnectionWithoutAClientIsNotAsked(): void
    {
        if (!class_exists(TemporalTaskQueueProbe::class)) {
            self::markTestSkipped('Needs gplanchat/durable-bridge-temporal in the project that runs the suite.');
        }
        $this->app->instance(TemporalConnection::class, TemporalConnection::fromDsn('temporal://127.0.0.1:7233?namespace=default&tls=0'));

        $rows = WorkerPresence::of($this->app)->rows();

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['polling']);
        self::assertNotNull($rows[0]['error']);
    }
}
