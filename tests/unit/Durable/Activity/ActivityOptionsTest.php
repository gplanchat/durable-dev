<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Activity;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\TaskQueue;
use PHPUnit\Framework\TestCase;

/**
 * The {@see ActivityOptions::of()} factory is only a shorter way of writing the constructor:
 * what has to be true is that the two produce the same wire object.
 */
final class ActivityOptionsTest extends TestCase
{
    public function testTheShortFormIsTheLongFormWordForWord(): void
    {
        self::assertEquals(
            new ActivityOptions(
                RetryLimit::ofAttempts(5),
                Duration::seconds(2.0),
                nonRetryableExceptions: [\DomainException::class],
                taskQueue: TaskQueue::named('payments'),
                timeouts: ActivityTimeouts::attempt(Duration::seconds(120.0)),
                summary: 'Charge order payment',
            ),
            ActivityOptions::of(5, 120, 2, [\DomainException::class], 'payments', summary: 'Charge order payment'),
        );
    }

    public function testABareDurationBoundsOneAttempt(): void
    {
        $options = ActivityOptions::of(3, 30);

        self::assertSame(3, $options->retryLimit->maxAttempts());
        self::assertEquals(Duration::seconds(30.0), $options->timeouts->startToClose);
        self::assertNull($options->timeouts->scheduleToClose);
    }

    public function testValueObjectsStillPassThrough(): void
    {
        self::assertEquals(
            ActivityOptions::of(3, 30),
            ActivityOptions::of(RetryLimit::ofAttempts(3), ActivityTimeouts::attempt(Duration::seconds(30.0))),
        );
    }

    public function testAnAttemptCountOfZeroIsRefusedRatherThanReadAsUnlimited(): void
    {
        // The magic value RetryLimit exists to remove does not get in through the factory.
        $this->expectException(\InvalidArgumentException::class);

        ActivityOptions::of(0);
    }

    public function testNoArgumentsIsTheDefault(): void
    {
        self::assertEquals(ActivityOptions::default(), ActivityOptions::of());
    }

    public function testAnUnlimitedBackoffSettlesOnTheCapRatherThanOverflowing(): void
    {
        // Around the thousandth attempt, 2.0 ** n overflows the float. The cap applies all the
        // same: an overflowed factor means "the cap".
        self::assertEquals(
            Duration::seconds(100.0),
            ActivityOptions::default()->retryDelayBeforeAttempt(1100),
        );
    }
}
