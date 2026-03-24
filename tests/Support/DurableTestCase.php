<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Support;

use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Tests\Support\Constraint\DistributedWorkflowJournalEquivalentConstraint;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

abstract class DurableTestCase extends TestCase
{
    private ?EventStoreInterface $eventStore = null;

    private ?InMemoryActivityTransport $activityTransport = null;

    private ?ActivityExecutor $activityExecutor = null;

    private ?ExecutionRuntime $runtime = null;

    /**
     * @return array{eventStore: InMemoryEventStore, activityTransport: InMemoryActivityTransport, activityExecutor: RegistryActivityExecutor, runtime: ExecutionRuntime}
     */
    protected function stack(): array
    {
        $this->eventStore = new InMemoryEventStore();
        $this->activityTransport = new InMemoryActivityTransport();
        $this->activityExecutor = new RegistryActivityExecutor();
        $this->runtime = new ExecutionRuntime(
            $this->eventStore,
            $this->activityTransport,
            $this->activityExecutor,
        );

        return [
            'eventStore' => $this->eventStore,
            'activityTransport' => $this->activityTransport,
            'activityExecutor' => $this->activityExecutor,
            'runtime' => $this->runtime,
        ];
    }

    protected function executionId(): string
    {
        return (string) Uuid::v7();
    }

    protected function eventStore(): EventStoreInterface
    {
        if (null === $this->eventStore) {
            $this->stack();
        }

        return $this->eventStore;
    }

    protected function runtime(): ExecutionRuntime
    {
        if (null === $this->runtime) {
            $this->stack();
        }

        return $this->runtime;
    }

    protected function activityExecutor(): ActivityExecutor
    {
        if (null === $this->activityExecutor) {
            $this->stack();
        }

        return $this->activityExecutor;
    }

    /**
     * @param class-string<Event> ...$expectedTypes
     */
    protected function assertEventTypesOrder(string $executionId, string ...$expectedTypes): void
    {
        $this->assertEventTypesOrderOn($this->eventStore(), $executionId, ...$expectedTypes);
    }

    /**
     * @param class-string<Event> ...$expectedTypes
     */
    protected function assertEventTypesOrderOn(EventStoreInterface $store, string $executionId, string ...$expectedTypes): void
    {
        $events = iterator_to_array($store->readStream($executionId));
        $actualTypes = array_map(static fn (Event $e) => $e::class, $events);

        $this->assertSame($expectedTypes, $actualTypes);
    }

    protected function drainActivityQueueOnce(ExecutionContext $context): void
    {
        $this->runtime()->drainActivityQueueOnce($context);
    }

    protected function runUntilIdle(ExecutionContext $context): void
    {
        $this->runtime()->runUntilIdle($context);
    }

    /**
     * Affirme que le journal réel est sémantiquement équivalent au journal attendu
     * (ordre, types, données métier ; pas les UUID d'activité).
     */
    final protected function assertDistributedWorkflowJournalEquivalent(
        InMemoryEventStore $actualJournal,
        InMemoryEventStore $expectedJournal,
        ExecutionId $executionId,
        string $message = '',
    ): void {
        $this->assertThat(
            $actualJournal,
            new DistributedWorkflowJournalEquivalentConstraint($expectedJournal, $executionId),
            $message,
        );
    }

    /**
     * @param list<array{name: string, payload: array}> $expectedOrdered activités en file (ordre FIFO)
     */
    final protected function assertActivityTransportPendingEquals(
        InMemoryActivityTransport $transport,
        array $expectedOrdered,
        string $message = '',
    ): void {
        $this->assertSame(
            $expectedOrdered,
            $transport->inspectPendingActivities(),
            $message,
        );
        $this->assertSame(
            \count($expectedOrdered),
            $transport->pendingCount(),
            '' !== $message ? $message.' (pendingCount)' : 'pendingCount doit correspondre au snapshot',
        );
    }
}
