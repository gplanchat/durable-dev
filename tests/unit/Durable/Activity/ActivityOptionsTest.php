<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Activity;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
#[CoversClass(ActivityOptions::class)]
final class ActivityOptionsTest extends \PHPUnit\Framework\TestCase
{
    #[Test]
    public function retryDelayBeforeAttemptFollowsExponentialBackoffWithCap(): void
    {
        $o = (new ActivityOptions())
            ->withInitialInterval(1.0)
            ->withBackoffCoefficient(2.0)
            ->withMaximumInterval(2.5)
        ;

        self::assertSame(0.0, $o->retryDelayBeforeAttempt(1));
        self::assertSame(1.0, $o->retryDelayBeforeAttempt(2));
        self::assertSame(2.0, $o->retryDelayBeforeAttempt(3));
        self::assertSame(2.5, $o->retryDelayBeforeAttempt(4));
    }

    #[Test]
    public function toMetadataAndFromMetadataRoundTripTemporalAlignedFields(): void
    {
        $o = (new ActivityOptions())
            ->withTaskQueue('heavy-jobs')
            ->withActivityId('biz-42')
            ->withScheduleToStartTimeoutSeconds(5.0)
            ->withStartToCloseTimeoutSeconds(30.0)
            ->withSummary('Do the thing')
            ->withCancellationType(ActivityCancellationType::WaitCancellationCompleted)
        ;

        $restored = ActivityOptions::fromMetadata($o->toMetadata());
        self::assertNotNull($restored);
        self::assertSame('heavy-jobs', $restored->taskQueue);
        self::assertSame('biz-42', $restored->activityId);
        self::assertSame(5.0, $restored->scheduleToStartTimeoutSeconds);
        self::assertSame(30.0, $restored->startToCloseTimeoutSeconds);
        self::assertSame('Do the thing', $restored->summary);
        self::assertSame(ActivityCancellationType::WaitCancellationCompleted, $restored->cancellationType);
    }
}
