<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Gplanchat\Durable\Exception\ActivityAttemptDeferred;
use Gplanchat\Durable\Exception\ResumeArrivedBeforeItsOutcome;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\DurableModule\Runtime\TableQueue\QueuedMessage;
use Gplanchat\DurableModule\Runtime\TableQueue\Queues;
use Magento\Framework\DB\Adapter\ConnectionException;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * One turn of `durable:worker` on the SQL backend (#736): takes a message from a queue and handles
 * it under the per-execution lock (resume, timer) or the attempt claim (activity).
 *
 * A message is acknowledged once it is handled and not before. Three outcomes:
 *
 * - handled: acknowledged;
 * - not now (another worker holds the execution or the attempt, the resume arrived before its
 *   outcome, a lock wait timeout, a deadlock, a lost connection): given back with
 *   {@see TableQueue::release()}, delivered again after `$deferSeconds`, nothing lost;
 * - never (an unreadable body, a failure the handlers do not journal): acknowledged, and the run
 *   it belongs to is journalled as failed, so that it does not wait for a message that is gone.
 *
 * If the release or the acknowledgement itself fails, the lease runs out and the message comes
 * back: the journal's guards answer the second copy.
 *
 * ponytail: no cap on how many times a message is given back (the table has no attempt counter); add one with `attempts` on the row if a stuck execution needs to stop.
 * The activity attempt claim is held by the core's processor for the whole attempt: this worker
 * checks `holds()` on the execution lock before each handler, not inside the activity.
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

            try {
                $message = $this->backend->queue->take($queue);
            } catch (\Throwable $e) {
                if (!self::isTransient($e)) {
                    throw $e;
                }
                $this->logger->warning('The durable queue could not be read, trying again.', ['queue' => $queue, 'exception' => $e]);

                return false;
            }
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
        $executionId = null;

        try {
            $body = match ($queue) {
                Queues::RESUME => Queues::decode($message->body, ResumeWorkflowMessage::class),
                Queues::TIMER => Queues::decode($message->body, FireWorkflowTimersMessage::class),
                Queues::ACTIVITY => Queues::decode($message->body, ActivityMessage::class),
                default => throw new \InvalidArgumentException('Unknown durable queue.'),
            };
            $executionId = $body->executionId;
            $handled = $this->process($body);
        } catch (\Throwable $e) {
            $handled = false;
            if (!self::isTransient($e)) {
                throw $e;
            } else {
                $this->logger->warning('A durable message is given back to the queue.', ['queue' => $queue, 'executionId' => $executionId, 'exception' => $e]);
            }
        }

        try {
            $handled ? $this->backend->queue->ack($message) : $this->backend->queue->release($message, $this->deferSeconds);
        } catch (\Throwable $e) {
            $this->logger->warning('A durable message could not be settled in the queue: its lease will run out and it will be delivered again.', ['queue' => $queue, 'executionId' => $executionId, 'exception' => $e]);
        }
    }

    /** @return bool true when the message is done with, false when it is to be delivered again */
    private function process(ResumeWorkflowMessage|FireWorkflowTimersMessage|ActivityMessage $body): bool
    {
        if ($body instanceof ActivityMessage) {
            try {
                // A non-retryable failure is returned: already journalled, and the queue must not retry it.
                $this->backend->activities->process($body);
            } catch (ActivityAttemptDeferred) {
                return false;
            }

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
        } catch (ResumeArrivedBeforeItsOutcome) {
            return false;
        } finally {
            $lock->release($body->executionId);
        }

        return true;
    }

    private static function isTransient(\Throwable $e): bool
    {
        return $e instanceof LockWaitException
            || $e instanceof DeadlockException
            || $e instanceof ConnectionException
            || $e instanceof ResumeArrivedBeforeItsOutcome
            || $e instanceof ActivityAttemptDeferred;
    }
}
