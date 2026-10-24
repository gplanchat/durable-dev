<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\Message;
use Gplanchat\Durable\Observation\Wait;
use PHPUnit\Framework\TestCase;

/**
 * What a run waits on, stored as a kind and its parameters so a dashboard can translate it and
 * format the date itself (#850). Text written before that stays readable.
 */
final class WaitTest extends TestCase
{
    public function testATimerIsWordedAsItAlwaysWasAndKeepsItsParameters(): void
    {
        $wait = Wait::fromStorage(Wait::timer('grace period', 1790244000)->toStorage());

        self::assertSame('timer "grace period" due at 2026-09-24T10:00:00+00:00', $wait->toString());
        self::assertEquals(
            new Message('run.waiting_on_timer', ['name' => 'grace period', 'due' => '2026-09-24 10:00:00']),
            $wait->message(),
        );
    }

    public function testATimerWithoutASummaryHasItsOwnKey(): void
    {
        $wait = Wait::fromStorage(Wait::timer('', 1790244000)->toStorage());

        self::assertSame('timer due at 2026-09-24T10:00:00+00:00', $wait->toString());
        self::assertEquals(new Message('run.waiting_on_timer_unnamed', ['due' => '2026-09-24 10:00:00']), $wait->message());
    }

    public function testAnActivityAndItsAttempt(): void
    {
        $activity = Wait::fromStorage(Wait::activity('charge')->toStorage());
        $attempt = Wait::fromStorage(Wait::attempt('charge', 2)->toStorage());

        self::assertSame('activity charge', $activity->toString());
        self::assertEquals(new Message('run.waiting_on_activity', ['name' => 'charge']), $activity->message());
        self::assertSame('activity charge attempt 2 in flight', $attempt->toString());
        self::assertEquals(new Message('run.waiting_on_attempt', ['name' => 'charge', 'attempt' => 2]), $attempt->message());
    }

    public function testATextWrittenBeforeTheKindsExistedIsTheReason(): void
    {
        $wait = Wait::fromStorage('timer due at 2026-09-24T10:00:00+00:00');

        self::assertSame('timer due at 2026-09-24T10:00:00+00:00', $wait->toString());
        self::assertSame('timer due at 2026-09-24T10:00:00+00:00', $wait->toStorage());
        self::assertEquals(new Message('run.waiting_on', ['reason' => 'timer due at 2026-09-24T10:00:00+00:00']), $wait->message());
    }

    public function testACondition(): void
    {
        self::assertEquals(new Message('run.waiting_on', ['reason' => 'condition at Foo.php:12']), Wait::text('condition at Foo.php:12')->message());
    }

    public function testBracesThatAreNotAWaitStayText(): void
    {
        foreach (['{not json', '{"kind":"unknown"}', '{"kind":"timer"}', '{}'] as $stored) {
            self::assertSame($stored, Wait::fromStorage($stored)->toString());
        }
    }
}
