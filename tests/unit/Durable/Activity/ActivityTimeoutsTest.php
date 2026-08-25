<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Activity;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Duration;
use PHPUnit\Framework\TestCase;

/**
 * Les quatre bornes ne se lisent pas isolément : c'est leur composition qui a un sens.
 */
final class ActivityTimeoutsTest extends TestCase
{
    public function testNoneMeansTheBackendDecides(): void
    {
        self::assertTrue(ActivityTimeouts::none()->areUnbounded());
        self::assertTrue(ActivityOptions::default()->timeouts->areUnbounded());
    }

    public function testAHeartbeatLongerThanTheAttemptIsRejected(): void
    {
        // La tentative se terminerait avant le premier battement manqué : la borne serait morte.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot exceed start-to-close/');

        new ActivityTimeouts(
            startToClose: Duration::seconds(10.0),
            heartbeat: Duration::seconds(30.0),
        );
    }

    public function testTheExecutionBoundNamesTheServerRequirement(): void
    {
        // Temporal refuse une activité sans borne de fermeture ; le repli est nommé, pas caché.
        $fallback = Duration::seconds(30.0);

        self::assertSame(30.0, ActivityTimeouts::none()->executionBoundOr($fallback)->toSeconds());
        self::assertSame(
            5.0,
            ActivityTimeouts::attempt(Duration::seconds(5.0))->executionBoundOr($fallback)->toSeconds(),
        );
        self::assertSame(
            7.0,
            (new ActivityTimeouts(scheduleToClose: Duration::seconds(7.0)))->executionBoundOr($fallback)->toSeconds(),
            'à défaut de borne de tentative, la borne de bout en bout fait office',
        );
    }

    public function testMetadataRoundTripKeepsSecondsOnTheWire(): void
    {
        $timeouts = new ActivityTimeouts(
            scheduleToStart: Duration::seconds(1.5),
            startToClose: Duration::seconds(30.0),
            scheduleToClose: Duration::minutes(5.0),
            heartbeat: Duration::seconds(10.0),
        );

        $metadata = $timeouts->toMetadata();
        self::assertSame(30.0, $metadata['start_to_close_timeout_seconds']);
        self::assertSame(300.0, $metadata['schedule_to_close_timeout_seconds']);

        $decoded = ActivityTimeouts::fromMetadata($metadata);
        self::assertSame(1.5, $decoded->scheduleToStart?->toSeconds());
        self::assertSame(10.0, $decoded->heartbeat?->toSeconds());
    }

    public function testUnsetTimeoutsAreAbsentFromTheWireRatherThanZero(): void
    {
        self::assertSame([], ActivityTimeouts::none()->toMetadata());
        self::assertTrue(ActivityTimeouts::fromMetadata([])->areUnbounded());
    }

    public function testActivityOptionsRoundTripsItsTimeouts(): void
    {
        $options = new ActivityOptions(timeouts: ActivityTimeouts::attempt(Duration::seconds(45.0)));
        $decoded = ActivityOptions::fromMetadata($options->toMetadata());

        self::assertSame(45.0, $decoded?->timeouts->startToClose?->toSeconds());
    }
}
