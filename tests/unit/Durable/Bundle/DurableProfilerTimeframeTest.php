<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Bundle;

use Gplanchat\Durable\Bundle\Profiler\DurableProfilerTimeframe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class DurableProfilerTimeframeTest extends TestCase
{
    #[Test]
    public function monotonicUnixSecondsUsesRecordedAtAndPreservesOrder(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-30 12:00:00.000000', new \DateTimeZone('UTC'));
        $t1 = new \DateTimeImmutable('2026-03-30 12:00:01.500000', new \DateTimeZone('UTC'));

        $times = DurableProfilerTimeframe::monotonicUnixSecondsFromRecordedEntries([
            ['recordedAt' => $t0],
            ['recordedAt' => $t1],
        ]);

        self::assertCount(2, $times);
        self::assertSame(1.5, $times[1] - $times[0], 'écart = 1,5 s entre les deux recorded_at');
    }

    #[Test]
    public function boundsForProcessTraceNonActivityUsesNextEventTimestamp(): void
    {
        $b = DurableProfilerTimeframe::boundsForProcessTraceEntry(
            1000.0,
            1000.25,
            'dispatch',
            0.0,
        );

        self::assertSame(1000.0, $b['startSec']);
        self::assertSame(1000.25, $b['endSec']);
    }

    #[Test]
    public function boundsForProcessActivityUsesDuration(): void
    {
        $b = DurableProfilerTimeframe::boundsForProcessTraceEntry(
            2000.0,
            3000.0,
            'activity',
            0.1,
        );

        self::assertSame(1999.9, $b['startSec']);
        self::assertSame(2000.0, $b['endSec']);
    }
}
