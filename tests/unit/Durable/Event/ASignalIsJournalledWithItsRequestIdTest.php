<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Event;

use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use PHPUnit\Framework\TestCase;

/**
 * DUR052: a delivered signal is journalled with the request id of the message that carried it, so
 * a resume can name it, and a redelivered message can tell it is already there.
 */
final class ASignalIsJournalledWithItsRequestIdTest extends TestCase
{
    public function testTheRequestIdSurvivesTheJournalRecord(): void
    {
        $event = new WorkflowSignalReceived(ExecutionId::fromString('exec-1'), 'approve', ['by' => 'ops'], 'req-1');

        $read = EventDataMapper::toDomainEvent(EventDataMapper::fromDomainEvent($event));

        self::assertInstanceOf(WorkflowSignalReceived::class, $read);
        self::assertSame('req-1', $read->requestId());
    }

    public function testASignalJournalledBeforeHasNone(): void
    {
        $read = EventDataMapper::toDomainEvent([
            'execution_id' => 'exec-1',
            'event_type' => WorkflowSignalReceived::class,
            'payload' => ['signalName' => 'approve', 'signalPayload' => []],
        ]);

        self::assertInstanceOf(WorkflowSignalReceived::class, $read);
        self::assertNull($read->requestId());
    }

    public function testASignalFactIsTheSignalWithThatRequestId(): void
    {
        $journal = new InMemoryEventStore();
        $journal->append(new WorkflowSignalReceived(ExecutionId::fromString('exec-1'), 'approve', [], 'req-1'));

        self::assertTrue(AwaitedFact::signal('req-1')->isJournalledIn($journal, ExecutionId::fromString('exec-1')));
        self::assertFalse(AwaitedFact::signal('req-2')->isJournalledIn($journal, ExecutionId::fromString('exec-1')));
    }
}
