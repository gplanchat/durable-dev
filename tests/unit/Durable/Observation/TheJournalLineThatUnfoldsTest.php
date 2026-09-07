<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A frieze says "what". An operator asks "with what" in the second that follows.
 *
 * `WorkflowRunEvent` only carried the sequence, the timestamp, the lane and a label: enough to
 * file the rows, not enough to answer the second question. An unfoldable built on that would have
 * opened onto nothing.
 */
final class TheJournalLineThatUnfoldsTest extends TestCase
{
    public function testAnEventCarriesWhatItWasCalledWith(): void
    {
        $history = $this->read([
            new ActivityScheduled('exec-1', 'act-1', 'charge', ['orderId' => 'ORD-4242'], []),
        ]);

        self::assertSame(WorkflowRunEventKind::Activity, $history[0]->kind);
        self::assertNotSame([], $history[0]->details, 'a scheduled activity has something to unfold');
        self::assertStringContainsString(
            'ORD-4242',
            json_encode($history[0]->details, \JSON_THROW_ON_ERROR),
            "the activity's input must be readable in the detail",
        );
    }

    public function testTheResultIsThereToo(): void
    {
        $history = $this->read([
            new ActivityScheduled('exec-1', 'act-1', 'charge', ['orderId' => 'ORD-4242'], []),
            new ActivityCompleted('exec-1', 'act-1', ['receipt' => 'rcpt-7']),
        ]);

        self::assertStringContainsString(
            'rcpt-7',
            json_encode($history[1]->details, \JSON_THROW_ON_ERROR),
            'an operator comes to read what the payload answered, not only that it answered',
        );
    }

    public function testTheFieldIsAdditive(): void
    {
        // The field lands at the end of the constructor with a default value: every caller
        // written before it — the Temporal bridge, the Sylius plugin, the tests — still builds.
        $event = new WorkflowRunEvent(1, new \DateTimeImmutable('@0'), WorkflowRunEventKind::Other, 'x');

        self::assertSame([], $event->details);
    }

    /**
     * @param list<\Gplanchat\Durable\Event\Event> $events
     *
     * @return list<WorkflowRunEvent>
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
