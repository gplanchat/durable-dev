<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\DurableModule\Runtime\TableQueue\QueuedMessage;
use Gplanchat\DurableModule\Runtime\TableQueue\Queues;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * One turn of `durable:worker` on the SQL backend (#736): takes a message from a queue and handles
 * it under the per-execution lock (resume, timer) or the attempt claim (activity).
 *
 * A message is acknowledged once it is handled and not before. When another worker holds the
 * execution, it is given back with {@see TableQueue::release()} and delivered again after
 * `$deferSeconds`. An exception leaves the message leased: it comes back when the lease ends.
 */
final class DatabaseWorker
{
    private int $next = 0;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseBackend $backend,
        ?LoggerInterface $logger = null,
        private readonly float $deferSeconds = 0.5,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Handles one message of the first of `$queues` that has one, starting after the one served
     * last so that a busy queue does not starve the others.
     *
     * @param list<string> $queues
     *
     * @return bool whether a message was taken
     */
    public function tick(array $queues = [Queues::RESUME, Queues::TIMER, Queues::ACTIVITY]): bool
    {
        $count = \count($queues);
        for ($i = 0; $i < $count; ++$i) {
            $queue = $queues[($this->next + $i) % $count];
            $message = $this->backend->queue->take($queue);
            if (null !== $message) {
                $this->next = ($this->next + $i + 1) % $count;
                $this->handle($queue, $message);

                return true;
            }
        }

        return false;
    }

    private function handle(string $queue, QueuedMessage $message): void
    {
        $body = match ($queue) {
            Queues::RESUME => Queues::decode($message->body, ResumeWorkflowMessage::class),
            Queues::TIMER => Queues::decode($message->body, FireWorkflowTimersMessage::class),
            Queues::ACTIVITY => Queues::decode($message->body, ActivityMessage::class),
            default => throw new \InvalidArgumentException('Unknown durable queue.'),
        };
        $handled = $this->process($body);

        try {
            $handled ? $this->backend->queue->ack($message) : $this->backend->queue->release($message, $this->deferSeconds);
        } catch (\Throwable $e) {
            $this->logger->warning('A durable message could not be settled in the queue: its lease will run out and it will be delivered again.', ['queue' => $queue, 'executionId' => $body->executionId, 'exception' => $e]);
        }
    }

    /** @return bool true when the message is done with, false when it is to be delivered again */
    private function process(ResumeWorkflowMessage|FireWorkflowTimersMessage|ActivityMessage $body): bool
    {
        if ($body instanceof ActivityMessage) {
            // A non-retryable failure comes back as a value: already journalled, and the queue must not retry it.
            $this->backend->activities->process($body);

            return true;
        }

        $lock = $this->backend->lock;
        if (!$lock->tryAcquire($body->executionId)) {
            return false;
        }

        try {
            // The lock lives on a connection that Magento's adapter reconnects silently: ask the server before replaying.
            if (!$lock->holds($body->executionId)) {
                return false;
            }
            $body instanceof ResumeWorkflowMessage ? ($this->backend->resumeHandler)($body) : ($this->backend->timerHandler)($body);
        } finally {
            $lock->release($body->executionId);
        }

        return true;
    }
}
