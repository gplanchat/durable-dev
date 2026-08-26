<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Mapping;

use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Mapping\EventDataMapper;
use PHPUnit\Framework\TestCase;

/**
 * `event_type` persiste le FQCN : une clé de payload manquante côté relecture perd la donnée
 * en silence, sans erreur nulle part.
 */
final class EventDataMapperRoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{Event}>
     */
    public static function events(): iterable
    {
        yield 'ActivityTaskFailed' => [new ActivityTaskFailed(
            'exec-1', 'act-1', 'charge', 3, 'App\\Boom', 'kaput', ActivityRetryState::MaximumAttemptsReached,
        )];
        yield 'ActivityFailed with retryState' => [new ActivityFailed(
            'exec-1', 'act-1', 'App\\Boom', 'kaput', 7, ['k' => 'v'], 'trace', [], 'charge', 3,
            ActivityRetryState::NonRetryableFailure,
        )];
        yield 'ActivityFailed legacy without retryState' => [new ActivityFailed('exec-1', 'act-1', 'App\\Boom', 'kaput')];
        yield 'TimerCancelled' => [new TimerCancelled('exec-1', 'timer-1', 'race_superseded')];
        yield 'WorkflowExecutionCancelled' => [new WorkflowExecutionCancelled('exec-1', 'parent_request_cancel', 'parent-1')];
        yield 'WorkflowExecutionCancelled without parent' => [new WorkflowExecutionCancelled('exec-1', 'operator')];
    }

    /**
     * @dataProvider events
     */
    public function testRoundTripPreservesEveryField(Event $event): void
    {
        $decoded = EventDataMapper::toDomainEvent(EventDataMapper::fromDomainEvent($event));

        self::assertInstanceOf($event::class, $decoded);
        self::assertSame($event->executionId(), $decoded->executionId());
        self::assertEquals($event->payload(), $decoded->payload());
    }

    public function testRoundTripSurvivesJsonEncodedPayloads(): void
    {
        $record = EventDataMapper::fromDomainEvent(new ActivityTaskFailed(
            'exec-1', 'act-1', 'charge', 2, 'App\\Boom', 'kaput', ActivityRetryState::InProgress,
        ));
        $record['payload'] = json_encode($record['payload'], \JSON_THROW_ON_ERROR);

        $decoded = EventDataMapper::toDomainEvent($record);
        self::assertInstanceOf(ActivityTaskFailed::class, $decoded);
        self::assertTrue($decoded->willRetry());
        self::assertSame(2, $decoded->attempt());
    }
}
