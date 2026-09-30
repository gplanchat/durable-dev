<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Mapping;

use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityRetryQueued;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Mapping\EventDataMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `event_type` persists the FQCN: a payload key missing on the read-back side loses the data
 * silently, with no error anywhere.
 */
final class EventDataMapperRoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{Event}>
     */
    public static function events(): iterable
    {
        yield 'ActivityTaskFailed' => [new ActivityTaskFailed(
            ExecutionId::fromString('exec-1'),
            'act-1',
            'charge',
            3,
            'App\\Boom',
            'kaput',
            ActivityRetryState::MaximumAttemptsReached,
        )];
        yield 'ActivityFailed with retryState' => [new ActivityFailed(
            ExecutionId::fromString('exec-1'),
            'act-1',
            'App\\Boom',
            'kaput',
            7,
            ['k' => 'v'],
            'trace',
            [],
            'charge',
            3,
            ActivityRetryState::NonRetryableFailure,
        )];
        yield 'ActivityFailed legacy without retryState' => [new ActivityFailed(ExecutionId::fromString('exec-1'), 'act-1', 'App\\Boom', 'kaput')];
        yield 'ActivityRetryQueued' => [new ActivityRetryQueued(ExecutionId::fromString('exec-1'), 'act-1', 3)];
        yield 'TimerCancelled' => [new TimerCancelled(ExecutionId::fromString('exec-1'), 'timer-1', 'race_superseded')];
        yield 'WorkflowExecutionCancelled' => [new WorkflowExecutionCancelled(ExecutionId::fromString('exec-1'), 'parent_request_cancel', 'parent-1')];
        yield 'WorkflowExecutionCancelled without parent' => [new WorkflowExecutionCancelled(ExecutionId::fromString('exec-1'), 'operator')];
    }

    #[DataProvider('events')]
    public function testRoundTripPreservesEveryField(Event $event): void
    {
        $record = EventDataMapper::fromDomainEvent($event);
        // The record keeps the string id (#682): the value object is not what is stored.
        self::assertSame('exec-1', $record['execution_id']);
        $decoded = EventDataMapper::toDomainEvent($record);

        self::assertInstanceOf($event::class, $decoded);
        self::assertSame($event->executionId()->toString(), $decoded->executionId()->toString());
        self::assertEquals($event->payload(), $decoded->payload());
    }

    public function testRoundTripSurvivesJsonEncodedPayloads(): void
    {
        $record = EventDataMapper::fromDomainEvent(new ActivityTaskFailed(
            ExecutionId::fromString('exec-1'),
            'act-1',
            'charge',
            2,
            'App\\Boom',
            'kaput',
            ActivityRetryState::InProgress,
        ));
        $record['payload'] = json_encode($record['payload'], \JSON_THROW_ON_ERROR);

        $decoded = EventDataMapper::toDomainEvent($record);
        self::assertInstanceOf(ActivityTaskFailed::class, $decoded);
        self::assertTrue($decoded->willRetry());
        self::assertSame(2, $decoded->attempt());
    }
}
