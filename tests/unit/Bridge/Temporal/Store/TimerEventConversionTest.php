<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Store;

use Google\Protobuf\Duration;
use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Store\TemporalEventConverter;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\TimerStartedEventAttributes;

/**
 * `TimerScheduled::scheduledAt()` is the deadline on every store: the wake calculator, the wait
 * wording and `checkTimers()` read it that way. A Temporal timer converted with its start time
 * reads as already due (#586).
 */
final class TimerEventConversionTest extends TestCase
{
    public function testATimerIsDueAtItsStartPlusItsTimeout(): void
    {
        $attrs = new TimerStartedEventAttributes();
        $attrs->setTimerId('nap');
        $attrs->setStartToFireTimeout(new Duration(['seconds' => 90, 'nanos' => 500_000_000]));

        $event = new HistoryEvent();
        $event->setEventId(5);
        $event->setEventType(EventType::EVENT_TYPE_TIMER_STARTED);
        $event->setEventTime(new Timestamp(['seconds' => 1_790_000_000, 'nanos' => 250_000_000]));
        $event->setTimerStartedEventAttributes($attrs);

        $converted = (new TemporalEventConverter(ExecutionId::fromString('exec-1')))->convert($event);

        self::assertInstanceOf(TimerScheduled::class, $converted);
        self::assertSame('nap', $converted->timerId());
        self::assertEqualsWithDelta(1_790_000_090.75, $converted->scheduledAt(), 0.001);
    }
}
