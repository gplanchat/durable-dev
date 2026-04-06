<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Store;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Profiler\TemporalEventConverter;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Store\EventStoreInterface;
use Temporal\Api\Common\V1\WorkflowExecution;

/**
 * Temporal-backed read-through event store for the Symfony profiler / DataCollector.
 *
 * When the local in-memory store has no events for an execution (because the HTTP process is
 * separate from the worker process), this adapter fetches the workflow history directly from
 * Temporal and converts each HistoryEvent to the equivalent Durable Event.
 *
 * Write operations (append) always delegate to the local store so that the in-process
 * activity handshake between ActivityMessageProcessor and TemporalActivityWorker continues
 * to function correctly.
 *
 * @see TemporalEventConverter
 * @see DUR028
 */
final class TemporalReadThroughEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly EventStoreInterface $localStore,
        private readonly TemporalHistoryCursor $cursor,
        private readonly WorkflowClient $workflowClient,
    ) {
    }

    #[\Override]
    public function append(Event $event): void
    {
        $this->localStore->append($event);
    }

    /**
     * @return iterable<Event>
     */
    #[\Override]
    public function readStream(string $executionId): iterable
    {
        if ($this->localStore->countEventsInStream($executionId) > 0) {
            return $this->localStore->readStream($executionId);
        }

        return $this->streamFromTemporal($executionId);
    }

    /**
     * @return iterable<array{event: Event, recordedAt: \DateTimeImmutable|null}>
     */
    #[\Override]
    public function readStreamWithRecordedAt(string $executionId): iterable
    {
        if ($this->localStore->countEventsInStream($executionId) > 0) {
            return $this->localStore->readStreamWithRecordedAt($executionId);
        }

        return $this->streamFromTemporalWithTimestamps($executionId);
    }

    #[\Override]
    public function countEventsInStream(string $executionId): int
    {
        $local = $this->localStore->countEventsInStream($executionId);
        if ($local > 0) {
            return $local;
        }

        $count = 0;
        foreach ($this->streamFromTemporal($executionId) as $_) {
            ++$count;
        }

        return $count;
    }

    /**
     * @return \Generator<int, Event>
     */
    private function streamFromTemporal(string $executionId): \Generator
    {
        $execution = $this->buildExecution($executionId);
        $converter = new TemporalEventConverter($executionId);

        foreach ($this->cursor->events($execution) as $historyEvent) {
            $durableEvent = $converter->convert($historyEvent);
            if (null !== $durableEvent) {
                yield $durableEvent;
            }
        }
    }

    /**
     * @return \Generator<int, array{event: Event, recordedAt: \DateTimeImmutable|null}>
     */
    private function streamFromTemporalWithTimestamps(string $executionId): \Generator
    {
        $execution = $this->buildExecution($executionId);
        $converter = new TemporalEventConverter($executionId);

        foreach ($this->cursor->events($execution) as $historyEvent) {
            $durableEvent = $converter->convert($historyEvent);
            if (null !== $durableEvent) {
                yield [
                    'event' => $durableEvent,
                    'recordedAt' => $converter->timestampFor($historyEvent),
                ];
            }
        }
    }

    private function buildExecution(string $executionId): WorkflowExecution
    {
        return new WorkflowExecution([
            'workflow_id' => $this->workflowClient->workflowId($executionId),
        ]);
    }
}
