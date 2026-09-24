<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel used to read the same journal three times per execution, and count it five times
 * (#335). On a DBAL journal each one is a query, on Temporal a gRPC history fetch.
 */
final class ProfilerReadsEachJournalOnceTest extends TestCase
{
    public function testEachJournalIsReadOnceAndCountedOnce(): void
    {
        $inner = new InMemoryEventStore();
        $inner->append(new ActivityScheduled('exec-1', 'act-1', 'charge', []));
        $inner->append(new ActivityScheduled('exec-2', 'act-2', 'ship', []));
        $store = new class ($inner) implements EventStoreInterface {
            /** @var array<string, int> */
            public array $calls = [];

            public function __construct(private readonly InMemoryEventStore $inner) {}

            public function append(Event $event): void
            {
                $this->inner->append($event);
            }

            public function readStream(string $executionId): iterable
            {
                $this->calls[$executionId . ' read'] = ($this->calls[$executionId . ' read'] ?? 0) + 1;

                return $this->inner->readStream($executionId);
            }

            public function readStreamWithRecordedAt(string $executionId): iterable
            {
                $this->calls[$executionId . ' read'] = ($this->calls[$executionId . ' read'] ?? 0) + 1;

                return $this->inner->readStreamWithRecordedAt($executionId);
            }

            public function countEventsInStream(string $executionId): int
            {
                $this->calls[$executionId . ' count'] = ($this->calls[$executionId . ' count'] ?? 0) + 1;

                return $this->inner->countEventsInStream($executionId);
            }
        };
        $trace = new DurableExecutionTrace();
        $trace->onWorkflowDispatchRequested('exec-1', 'Order', [], false, 'async');

        $collector = new DurableDataCollector($trace, new InMemoryWorkflowMetadataStore(), $store);
        $collector->collect(new Request(['durable_execution' => 'exec-2']), new Response());

        // One indexed COUNT and one read that stops at the panel's limit: walking the whole
        // stream to count it would hydrate every payload of a long journal.
        ksort($store->calls);
        self::assertSame(['exec-1 count' => 1, 'exec-1 read' => 1, 'exec-2 count' => 1, 'exec-2 read' => 1], $store->calls);
        self::assertSame(2, $collector->getJournalEventCount());
    }
}
