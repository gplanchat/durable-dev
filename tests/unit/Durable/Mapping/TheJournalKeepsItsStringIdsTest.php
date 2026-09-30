<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Mapping;

use Gplanchat\Bridge\Temporal\Codec\TemporalActivityScheduleInput;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #682: an event is built from an `ExecutionId`, and what it writes stays what it wrote. The
 * object has no JSON form (`json_encode()` gives `{}`) and cannot be an array key, so every edge
 * that serialises or files an event writes the string.
 */
final class TheJournalKeepsItsStringIdsTest extends TestCase
{
    public function testTheRecordCarriesTheIdAsAString(): void
    {
        $record = EventDataMapper::fromDomainEvent(new TimerCompleted(ExecutionId::fromString('exec-1'), 'timer-1'));

        self::assertSame('exec-1', $record['execution_id']);
        self::assertSame(
            '{"execution_id":"exec-1","event_type":"Gplanchat\\\\Durable\\\\Event\\\\TimerCompleted","payload":{"timerId":"timer-1"}}',
            json_encode($record, \JSON_THROW_ON_ERROR),
        );
    }

    public function testTheTemporalActivityInputCarriesTheIdAsAString(): void
    {
        $input = TemporalActivityScheduleInput::encodeFromScheduled(
            new ActivityScheduled(ExecutionId::fromString('exec-1'), 'act-1', 'charge', ['amount' => 3]),
        );

        self::assertSame('exec-1', $input['executionId']);
        self::assertStringContainsString('"executionId":"exec-1"', json_encode($input, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{Event}>
     */
    public static function eventsThatNameAnotherExecution(): iterable
    {
        $id = ExecutionId::fromString('exec-1');
        yield 'ChildWorkflowScheduled' => [new ChildWorkflowScheduled($id, 'child-1', 'App\\Child', [])];
        yield 'WorkflowCancellationRequested' => [new WorkflowCancellationRequested($id, 'stop', 'parent-1')];
        yield 'WorkflowContinuedAsNew' => [new WorkflowContinuedAsNew($id, 'App\\Next', [], [], 'exec-2')];
        yield 'ExecutionStarted' => [new ExecutionStarted($id, ['continuedFromExecutionId' => 'exec-0'])];
        yield 'terminatedByParent' => [WorkflowExecutionFailed::terminatedByParent($id, 'parent-1')];
    }

    #[DataProvider('eventsThatNameAnotherExecution')]
    public function testAPayloadHoldsPlainDataOnly(Event $event): void
    {
        $payload = $event->payload();
        array_walk_recursive($payload, static function (mixed $value): void {
            self::assertFalse(\is_object($value), 'a payload value is an object, which json_encode() writes as {}');
        });
        self::assertStringNotContainsString('{}', json_encode($event->payload(), \JSON_THROW_ON_ERROR));
    }

    public function testTheInMemoryStoreFilesTheStreamUnderTheString(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new TimerCompleted(ExecutionId::fromString('exec-1'), 'timer-1'));

        self::assertCount(1, iterator_to_array($store->readStream(ExecutionId::fromString('exec-1')), false));
        self::assertSame(1, $store->countEventsInStream(ExecutionId::fromString('exec-1')));
        self::assertCount(0, iterator_to_array($store->readStream(ExecutionId::fromString('exec-2')), false));
    }
}
