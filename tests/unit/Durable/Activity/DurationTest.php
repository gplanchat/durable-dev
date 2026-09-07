<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Activity;

use Gplanchat\Durable\Duration;
use PHPUnit\Framework\TestCase;

/**
 * The unit lived in the field name (`…Seconds`), never in the type; the comparisons of the domain
 * were restated to every reader.
 */
final class DurationTest extends TestCase
{
    public function testUnitsConvertToSeconds(): void
    {
        self::assertSame(30.0, Duration::seconds(30.0)->toSeconds());
        self::assertSame(0.25, Duration::milliseconds(250)->toSeconds());
        self::assertSame(150.0, Duration::minutes(2.5)->toSeconds());
        self::assertSame(7200.0, Duration::hours(2)->toSeconds());
        self::assertTrue(Duration::zero()->isZero());
    }

    public function testANegativeDurationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be negative/');

        Duration::seconds(-1.0);
    }

    public function testAcceptsANativeInterval(): void
    {
        // Covers CarbonInterval without depending on Carbon: it extends DateInterval.
        self::assertSame(30.0, Duration::of(new \DateInterval('PT30S'))->toSeconds());
        self::assertSame(150.0, Duration::of(new \DateInterval('PT2M30S'))->toSeconds());
        self::assertSame(86400.0, Duration::of(new \DateInterval('P1D'))->toSeconds());
    }

    public function testAnInstantBecomesADurationOnlyRelativeToAnother(): void
    {
        // A DateTimeInterface — Carbon included — is an instant, not a length.
        $from = new \DateTimeImmutable('2026-01-01 12:00:00');
        $deadline = new \DateTimeImmutable('2026-01-01 12:01:30');

        self::assertSame(90.0, Duration::until($deadline, $from)->toSeconds());
    }

    public function testBoundaryCoercionAcceptsWhatTheCallerHas(): void
    {
        self::assertSame(90.0, Duration::from(90)->toSeconds());
        self::assertSame(90.0, Duration::from(90.0)->toSeconds());
        self::assertSame(300.0, Duration::from(new \DateInterval('PT5M'))->toSeconds());
        self::assertSame(42.0, Duration::from(Duration::seconds(42.0))->toSeconds());
    }

    public function testRoundTripsThroughANativeInterval(): void
    {
        $interval = Duration::minutes(2.5)->toDateInterval();

        self::assertSame(150.0, Duration::of($interval)->toSeconds());
    }

    public function testComparisonsAndArithmeticBelongToTheObject(): void
    {
        $short = Duration::seconds(5.0);
        $long = Duration::seconds(50.0);

        self::assertTrue($long->isLongerThan($short));
        self::assertFalse($short->isLongerThan($long));
        self::assertSame(5.0, $long->shortest($short)->toSeconds());
        self::assertSame(20.0, $short->multipliedBy(4.0)->toSeconds());
    }

    public function testElapsedIsAskedToTheDuration(): void
    {
        $timeout = Duration::seconds(10.0);

        self::assertFalse($timeout->hasElapsedSince(1000.0, 1009.0));
        self::assertTrue($timeout->hasElapsedSince(1000.0, 1011.0));
    }

    public function testWireDecodingTreatsZeroAndAbsentAsNoBound(): void
    {
        // Temporal convention: a timeout of 0 means "not set".
        self::assertNull(Duration::fromWireValue(null));
        self::assertNull(Duration::fromWireValue(0));
        self::assertNull(Duration::fromWireValue(-5));
        self::assertSame(12.5, Duration::fromWireValue(12.5)?->toSeconds());
    }

    public function testInfinityIsAValueAndNotAnAbsence(): void
    {
        $forever = Duration::infinity();

        self::assertTrue($forever->isInfinite());
        self::assertFalse(Duration::seconds(1.0)->isInfinite());
        // What null could not do: compare itself to other durations.
        self::assertEquals(Duration::seconds(30.0), $forever->shortest(Duration::seconds(30.0)));
        self::assertTrue($forever->isLongerThan(Duration::seconds(1e12)));
    }

    public function testAComputedInfinityIsRefusedRatherThanAccepted(): void
    {
        // An INF comes from an arithmetic mistake far more often than from an intention.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/finite number of seconds/');

        Duration::seconds(\INF);
    }
}
