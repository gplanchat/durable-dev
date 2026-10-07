<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Tests;

use Gplanchat\Durable\Observation\RunDashboard;
use Psr\Clock\ClockInterface;

/**
 * The dashboard measures waiting times against the clock durable-laravel binds, resolved by its
 * interface rather than by a string id a rename would break (#879), whichever id the application
 * rebinds.
 */
final class TheClockTest extends PanelTestCase
{
    public function testTheDashboardReadsTheClockBoundByItsInterface(): void
    {
        $clock = $this->frozen();
        $this->app->instance(ClockInterface::class, $clock);

        self::assertSame($clock, $this->dashboardClock());
    }

    public function testTheDashboardReadsAClockReboundUnderTheStringId(): void
    {
        // The #617 route: an application provider rebinds `durable.clock` after durable-laravel's.
        $clock = $this->frozen();
        $this->app->instance('durable.clock', $clock);

        self::assertSame($clock, $this->dashboardClock());
    }

    private function frozen(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@1700000000');
            }
        };
    }

    private function dashboardClock(): mixed
    {
        $this->app->forgetInstance(RunDashboard::class);
        $dashboard = $this->app->make(RunDashboard::class);

        return (new \ReflectionProperty($dashboard, 'clock'))->getValue($dashboard);
    }
}
