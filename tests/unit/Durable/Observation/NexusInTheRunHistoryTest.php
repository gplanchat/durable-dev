<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Bridge\Temporal\Store\TemporalRunHistoryReader;
use Gplanchat\Durable\Event\NexusOperationCompleted;
use Gplanchat\Durable\Event\NexusOperationScheduled;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A Nexus operation is the only place in an execution where the wait is **served by somebody
 * else**. An operator who sees a stuck workflow without seeing that operation will hunt for the
 * failure in their own system, when it lies outside.
 *
 * Until now they fell on the `Other` lane, listed but with no identity: no endpoint, no service,
 * no operation name — that is, without what says **at whose place** the wait happens.
 */
final class NexusInTheRunHistoryTest extends TestCase
{
    public function testAScheduledOperationGetsItsOwnLane(): void
    {
        $history = $this->read([
            new NexusOperationScheduled('exec-1', 5, 'paiements', 'facturation', 'encaisser', [], []),
        ]);

        self::assertSame(WorkflowRunEventKind::Nexus, $history[0]->kind);
    }

    public function testTheLabelSaysWhereTheWaitHappens(): void
    {
        $history = $this->read([
            new NexusOperationScheduled('exec-1', 5, 'paiements', 'facturation', 'encaisser', [], []),
        ]);

        self::assertStringContainsString('paiements', $history[0]->label);
        self::assertStringContainsString('facturation', $history[0]->label);
        self::assertStringContainsString('encaisser', $history[0]->label);
    }

    public function testATerminalEventBorrowsTheIdentityOfItsScheduling(): void
    {
        // Terminal events only carry the `scheduledEventId` — the same constraint as for
        // activities, where the name is read off the scheduling. Without that correlation, the
        // frieze would show "NexusOperationCompleted" and the operator would not know which one.
        $history = $this->read([
            new NexusOperationScheduled('exec-1', 5, 'paiements', 'facturation', 'encaisser', [], []),
            new NexusOperationCompleted('exec-1', 5, ['receipt' => 'r-1']),
        ]);

        self::assertCount(2, $history);
        self::assertSame(WorkflowRunEventKind::Nexus, $history[1]->kind);
        self::assertStringContainsString('encaisser', $history[1]->label);
    }

    public function testTheTemporalReaderUsesTheSameLane(): void
    {
        // Both backends feed the same frieze. If one files Nexus on its lane and the other on
        // `Other`, the same workflow reads differently depending on where it runs — and the
        // operator learns not to trust the lane.
        $kind = (new \ReflectionMethod(TemporalRunHistoryReader::class, 'kindOf'));
        $kind->setAccessible(true);

        foreach ([
            'EVENT_TYPE_NEXUS_OPERATION_SCHEDULED',
            'EVENT_TYPE_NEXUS_OPERATION_STARTED',
            'EVENT_TYPE_NEXUS_OPERATION_COMPLETED',
            'EVENT_TYPE_NEXUS_OPERATION_FAILED',
            'EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT',
            'EVENT_TYPE_NEXUS_OPERATION_CANCELED',
            'EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUESTED',
        ] as $eventType) {
            self::assertSame(
                WorkflowRunEventKind::Nexus,
                $kind->invoke(null, $eventType),
                $eventType . ' must fall on the Nexus lane',
            );
        }
    }

    /**
     * @param list<object> $events
     *
     * @return list<\Gplanchat\Durable\Observation\WorkflowRunEvent>
     */
    private function read(array $events): array
    {
        $store = new InMemoryEventStore();
        foreach ($events as $event) {
            $store->append($event);
        }

        return (new JournalRunHistoryReader($store))->read('exec-1');
    }
}
