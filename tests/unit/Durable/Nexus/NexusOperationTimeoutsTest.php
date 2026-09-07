<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use PHPUnit\Framework\TestCase;

/**
 * The verdicts of probe §1.3, made impossible to suffer.
 *
 * The server trims in silence: asking for 60 s of `startToClose` under 10 s of `scheduleToClose`
 * records 10 s, with no error. The value object refuses the combination at construction — that is
 * the only difference between a bound you believe you have and a bound you have.
 *
 * @see openspec/changes/temporal-nexus-support/design.md
 * @see tests/integration/Temporal/NexusOperationBoundsTest.php
 */
final class NexusOperationTimeoutsTest extends TestCase
{
    public function testNoBoundAtAll(): void
    {
        $timeouts = NexusOperationTimeouts::none();

        self::assertTrue($timeouts->areUnbounded());
        self::assertNull($timeouts->scheduleToClose);
        self::assertNull($timeouts->scheduleToStart);
        self::assertNull($timeouts->startToClose);
    }

    public function testAScheduleToStartLongerThanTheEnvelopeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schedule-to-close');

        new NexusOperationTimeouts(
            scheduleToClose: Duration::seconds(10),
            scheduleToStart: Duration::seconds(60),
        );
    }

    public function testAStartToCloseLongerThanTheEnvelopeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schedule-to-close');

        new NexusOperationTimeouts(
            scheduleToClose: Duration::seconds(10),
            startToClose: Duration::seconds(60),
        );
    }

    public function testASubBoundEqualToTheEnvelopeIsAccepted(): void
    {
        $timeouts = new NexusOperationTimeouts(
            scheduleToClose: Duration::seconds(10),
            scheduleToStart: Duration::seconds(10),
            startToClose: Duration::seconds(10),
        );

        self::assertFalse($timeouts->areUnbounded());
    }

    public function testAnInfiniteEnvelopeClampsNothing(): void
    {
        // On the wire, this envelope is written 0 — which the server reads as "no bound" and
        // which trims nothing. The domain's infinity says the same thing without disguising it as
        // zero seconds.
        $timeouts = new NexusOperationTimeouts(
            scheduleToClose: Duration::infinity(),
            startToClose: Duration::seconds(3600),
        );

        self::assertSame(3600.0, $timeouts->startToClose?->toSeconds());
    }

    public function testSubBoundsWithoutAnEnvelopeAreAccepted(): void
    {
        $timeouts = new NexusOperationTimeouts(
            scheduleToStart: Duration::seconds(60),
            startToClose: Duration::seconds(600),
        );

        self::assertFalse($timeouts->areUnbounded());
    }

    public function testWideningTheEnvelopeThroughAWitherIsRevalidated(): void
    {
        $timeouts = new NexusOperationTimeouts(startToClose: Duration::seconds(60));

        $this->expectException(\InvalidArgumentException::class);
        $timeouts->withScheduleToClose(Duration::seconds(10));
    }

    public function testAWitherKeepsTheOtherBounds(): void
    {
        $timeouts = NexusOperationTimeouts::none()
            ->withScheduleToClose(Duration::seconds(600))
            ->withStartToClose(Duration::seconds(60));

        self::assertSame(600.0, $timeouts->scheduleToClose?->toSeconds());
        self::assertSame(60.0, $timeouts->startToClose?->toSeconds());
    }
}
