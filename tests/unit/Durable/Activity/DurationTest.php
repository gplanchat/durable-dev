<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Activity;

use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Activity\Duration;
use PHPUnit\Framework\TestCase;

/**
 * L'unité vivait dans le nom du champ (`…Seconds`), jamais dans le type ; les comparaisons du
 * domaine étaient redites à chaque lecteur.
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
        // Couvre CarbonInterval sans dépendre de Carbon : il étend DateInterval.
        self::assertSame(30.0, Duration::of(new \DateInterval('PT30S'))->toSeconds());
        self::assertSame(150.0, Duration::of(new \DateInterval('PT2M30S'))->toSeconds());
        self::assertSame(86400.0, Duration::of(new \DateInterval('P1D'))->toSeconds());
    }

    public function testAnInstantBecomesADurationOnlyRelativeToAnother(): void
    {
        // Un DateTimeInterface — Carbon compris — est un instant, pas une longueur.
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
        // Convention Temporal : un timeout à 0 vaut « non renseigné ».
        self::assertNull(Duration::fromWireValue(null));
        self::assertNull(Duration::fromWireValue(0));
        self::assertNull(Duration::fromWireValue(-5));
        self::assertSame(12.5, Duration::fromWireValue(12.5)?->toSeconds());
    }
}
