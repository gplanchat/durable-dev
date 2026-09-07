<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Job;

/**
 * The activity transport port, on Laravel's queue.
 *
 * The same adaptation as {@see \Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport}:
 * `enqueue` pushes, `dequeue` pops and acknowledges. What changes is the vocabulary — `later()`
 * instead of a `DelayStamp`, `pop()` instead of a `ReceiverInterface`.
 *
 * **The deferral becomes the queue's, then disappears from the message.** That is the contract the
 * in-memory transport and the Messenger one already hold: a `retryDelay` that survived being put
 * on the queue would be waited out twice.
 *
 * In production nobody calls the "pull" half of this port: `queue:work` pushes the job into
 * `handle()`. It is implemented all the same, because a synchronous drain — a test, a command that
 * empties the queue by hand — has the right to exist, and because an `isEmpty()` that lied would
 * make a caller that still has work conclude "nothing left to do".
 */
final class LaravelActivityTransport implements ActivityTransportInterface
{
    private ?Job $pending = null;

    public function __construct(
        private readonly QueueFactory $queue,
        private readonly ?string $connection = null,
        private readonly ?string $queueName = null,
    ) {}

    public function enqueue(ActivityMessage $message): void
    {
        $delaySeconds = null !== $message->retryDelay ? $message->retryDelay->toSeconds() : 0.0;
        $job = new RunActivityJob($message->withoutRetryDelay());
        $connection = $this->queue->connection($this->connection);

        if ($delaySeconds > 0.0) {
            $connection->later((int) ceil($delaySeconds), $job, '', $this->queueName);

            return;
        }

        $connection->push($job, '', $this->queueName);
    }

    public function dequeue(): ?ActivityMessage
    {
        $job = $this->take();
        if (null === $job) {
            return null;
        }

        $message = self::messageOf($job);
        // Acknowledge in both cases: a job that is not ours has no business here, and leaving it
        // on the queue would make it come round again on every turn.
        $job->delete();

        return $message;
    }

    public function isEmpty(): bool
    {
        if (null !== $this->pending) {
            return false;
        }

        // Pop to find out, and **keep** what was popped: an `isEmpty()` that throws away the job
        // it has just taken out answers correctly once and loses work on every call.
        $this->pending = $this->queue->connection($this->connection)->pop($this->queueName);

        return null === $this->pending;
    }

    /** The queue carries the deferral itself: nothing to wait for on the PHP side. */
    public function nextDueAt(): ?float
    {
        return $this->isEmpty() ? null : microtime(true);
    }

    /** Best effort, and Laravel does not allow it: a queued job cannot be removed by its content. */
    public function removePendingFor(string $executionId, string $activityId): bool
    {
        return false;
    }

    private function take(): ?Job
    {
        if (null !== $this->pending) {
            $job = $this->pending;
            $this->pending = null;

            return $job;
        }

        return $this->queue->connection($this->connection)->pop($this->queueName);
    }

    private static function messageOf(Job $job): ?ActivityMessage
    {
        /** @var array{data?: array{command?: string}} $payload */
        $payload = $job->payload();
        $serialized = $payload['data']['command'] ?? null;
        if (!\is_string($serialized)) {
            return null;
        }

        $command = @unserialize($serialized);

        return $command instanceof RunActivityJob ? $command->message : null;
    }
}
