<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime\TableQueue;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;

/**
 * Where a distributed run puts its activities: the `durable_activity` queue. It only sends. A
 * worker takes with `TableQueue::take()` and acknowledges with `ack()`, to keep the lease, so
 * `dequeue()` and the two readers that serve a drain inside one process throw.
 */
final readonly class TableQueueActivityTransport implements ActivityTransportInterface
{
    public function __construct(private TableQueue $queue) {}

    public function enqueue(ActivityMessage $message): void
    {
        $delay = $message->retryDelay?->toSeconds() ?? 0.0;
        $this->queue->enqueue(Queues::ACTIVITY, Queues::encode($message->withoutRetryDelay()), $delay);
    }

    public function dequeue(): ?ActivityMessage
    {
        throw self::taken();
    }

    public function isEmpty(): bool
    {
        throw self::taken();
    }

    public function nextDueAt(): ?float
    {
        throw self::taken();
    }

    public function removePendingFor(ExecutionId $executionId, string $activityId): bool
    {
        return false;
    }

    private static function taken(): \LogicException
    {
        return new \LogicException('The activities of the database backend are taken by a worker with TableQueue::take(), not dequeued in process.');
    }
}
