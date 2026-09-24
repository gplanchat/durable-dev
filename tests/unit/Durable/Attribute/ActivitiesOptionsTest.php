<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Attribute;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Attribute\Activities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** An attribute argument cannot call Duration::seconds(): #[Activities] takes scalars and builds the options. */
final class ActivitiesOptionsTest extends TestCase
{
    public function testWithoutAnyOptionTheStubKeepsTheDefaults(): void
    {
        self::assertNull((new Activities(\stdClass::class))->options());
    }

    public function testEveryOptionReachesTheActivityOptions(): void
    {
        $options = (new Activities(
            \stdClass::class,
            attempts: 3,
            startToClose: 120.0,
            scheduleToClose: 600.0,
            scheduleToStart: 5.0,
            heartbeat: 10.0,
            initialInterval: 2.0,
            backoffCoefficient: 1.5,
            maximumInterval: 60.0,
            nonRetryable: [\DomainException::class],
            taskQueue: 'payments',
            cancellationType: ActivityCancellationType::WaitCancellationCompleted,
            summary: 'charge the card',
        ))->options();

        self::assertNotNull($options);
        self::assertSame(3, $options->retryLimit->maxAttempts());
        self::assertSame(120.0, $options->timeouts->startToClose?->toSeconds());
        self::assertSame(600.0, $options->timeouts->scheduleToClose?->toSeconds());
        self::assertSame(5.0, $options->timeouts->scheduleToStart?->toSeconds());
        self::assertSame(10.0, $options->timeouts->heartbeat?->toSeconds());
        self::assertSame(2.0, $options->initialInterval->toSeconds());
        self::assertSame(1.5, $options->backoffCoefficient);
        self::assertSame(60.0, $options->maximumInterval?->toSeconds());
        self::assertSame([\DomainException::class], $options->nonRetryableExceptions);
        self::assertSame('payments', $options->taskQueue?->name());
        self::assertSame(ActivityCancellationType::WaitCancellationCompleted, $options->cancellationType);
        self::assertSame('charge the card', $options->summary);
    }

    public function testOneOptionIsEnoughAndTheRestKeepsItsDefault(): void
    {
        $options = (new Activities(\stdClass::class, startToClose: 300.0))->options();

        self::assertSame(300.0, $options?->timeouts->startToClose?->toSeconds());
        self::assertTrue($options->retryLimit->isUnlimited());
        self::assertSame(2.0, $options->backoffCoefficient);
    }

    /**
     * @return iterable<string, array{Activities, string}>
     */
    public static function refusals(): iterable
    {
        yield 'no attempt' => [new Activities(\stdClass::class, attempts: 0), 'attempts'];
        yield 'a negative duration' => [new Activities(\stdClass::class, startToClose: -1.0), 'startToClose'];
        yield 'a heartbeat longer than an attempt' => [new Activities(\stdClass::class, startToClose: 5.0, heartbeat: 10.0), 'heartbeat'];
        yield 'a non-retryable entry that is no exception' => [new Activities(\stdClass::class, nonRetryable: [\stdClass::class]), 'nonRetryable'];
        yield 'a malformed task queue' => [new Activities(\stdClass::class, taskQueue: ''), 'taskQueue'];
    }

    #[DataProvider('refusals')]
    public function testAnImpossibleOptionIsRefusedAndNamed(Activities $attribute, string $parameter): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . $parameter . '/');

        $attribute->options();
    }
}
