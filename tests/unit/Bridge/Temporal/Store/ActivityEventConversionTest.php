<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Store;

use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Bridge\Temporal\Store\TemporalEventConverter;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\ExecutionId;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\ActivityType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\ActivityTaskScheduledEventAttributes;
use Temporal\Api\History\V1\HistoryEvent;

/**
 * The worker schedules an activity with an envelope (`TemporalActivityScheduleInput`), whose
 * `payload` slot holds the arguments. Read back whole, the envelope became the arguments, and the
 * journal the store returns disagreed with the one that was written (#326).
 */
final class ActivityEventConversionTest extends TestCase
{
    public function testTheScheduledActivityReadsBackAsItWasWritten(): void
    {
        $written = new ActivityScheduled(ExecutionId::fromString('exec-1'), 'act-1', 'quote', ['lines' => ['a', 'b']], ['queue' => 'default']);

        $event = new HistoryEvent();
        $event->setEventId(5);
        $event->setEventType(EventType::EVENT_TYPE_ACTIVITY_TASK_SCHEDULED);
        $event->setActivityTaskScheduledEventAttributes(new ActivityTaskScheduledEventAttributes([
            'activity_id' => 'act-1',
            'activity_type' => new ActivityType(['name' => 'quote']),
            'input' => TemporalActivityScheduleInput::toPayloads($written),
        ]));

        $read = (new TemporalEventConverter('exec-1'))->convert($event);

        self::assertInstanceOf(ActivityScheduled::class, $read);
        self::assertSame($written->payload(), $read->payload());
    }
}
