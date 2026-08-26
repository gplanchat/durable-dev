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
 * La fabrique {@see ActivityOptions::of()} n'est qu'une façon plus courte d'écrire le
 * constructeur : ce qui doit être vrai, c'est que les deux produisent le même objet de fil.
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
        // La valeur magique que RetryLimit existe pour supprimer ne rentre pas par la fabrique.
        $this->expectException(\InvalidArgumentException::class);

        ActivityOptions::of(0);
    }

    public function testNoArgumentsIsTheDefault(): void
    {
        self::assertEquals(ActivityOptions::default(), ActivityOptions::of());
    }

    public function testAnUnlimitedBackoffSettlesOnTheCapRatherThanOverflowing(): void
    {
        // Vers la millieme tentative, 2.0 ** n depasse le flottant. Le plafond s'applique
        // quand meme : un facteur deborde veut dire « le plafond ».
        self::assertEquals(
            Duration::seconds(100.0),
            ActivityOptions::default()->retryDelayBeforeAttempt(1100),
        );
    }
}
