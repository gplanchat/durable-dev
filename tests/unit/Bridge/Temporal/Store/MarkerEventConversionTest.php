<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal\Store;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Store\TemporalEventConverter;
use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\VersionMarked;
use Gplanchat\Durable\Event\WorkflowCancellationDelivered;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Versioning\ChangePoint;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\History\V1\MarkerRecordedEventAttributes;

/**
 * Durable writes three markers on Temporal, and only one of them is a side effect. Converting every
 * marker to `SideEffectRecorded` lost the recorded version and shifted every side-effect slot that
 * followed it, so a journal read through the store disagreed with the one the worker replays
 * (#326).
 */
final class MarkerEventConversionTest extends TestCase
{
    public function testEachMarkerConvertsToTheEventItRecords(): void
    {
        $converter = new TemporalEventConverter(ExecutionId::fromString('exec-1'));

        $version = $converter->convert(self::marker(1, ChangePoint::MARKER_NAME, [
            ChangePoint::DETAIL_CHANGE_ID => 'add-discount',
            ChangePoint::DETAIL_VERSION => 2,
        ]));
        $first = $converter->convert(self::marker(2, TemporalExecutionHistory::MARKER_SIDE_EFFECT, ['result' => ['deep' => true]]));
        $delivered = $converter->convert(self::marker(3, TemporalExecutionHistory::MARKER_CANCELLATION_DELIVERED, ['targets' => ['act-1']]));
        $second = $converter->convert(self::marker(4, TemporalExecutionHistory::MARKER_SIDE_EFFECT, ['result' => 'after']));

        self::assertInstanceOf(VersionMarked::class, $version);
        self::assertSame('add-discount', $version->changeId());
        self::assertSame(2, $version->version());

        self::assertInstanceOf(SideEffectRecorded::class, $first);
        self::assertSame(['deep' => true], $first->result());
        self::assertInstanceOf(WorkflowCancellationDelivered::class, $delivered);
        self::assertSame(['act-1'], $delivered->targets());
        self::assertInstanceOf(SideEffectRecorded::class, $second);
        self::assertSame('after', $second->result());
        self::assertSame(['0', '1'], [$first->sideEffectId(), $second->sideEffectId()], 'only side effects take a slot');
    }

    public function testAMarkerDurableDidNotWriteIsSkipped(): void
    {
        self::assertNull((new TemporalEventConverter(ExecutionId::fromString('exec-1')))->convert(self::marker(1, 'SomeoneElse', ['result' => 1])));
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function marker(int $eventId, string $name, array $details): HistoryEvent
    {
        $encoded = [];
        foreach ($details as $key => $value) {
            $encoded[$key] = JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($value));
        }

        $event = new HistoryEvent();
        $event->setEventId($eventId);
        $event->setEventType(EventType::EVENT_TYPE_MARKER_RECORDED);
        $event->setMarkerRecordedEventAttributes(new MarkerRecordedEventAttributes([
            'marker_name' => $name,
            'details' => $encoded,
        ]));

        return $event;
    }
}
