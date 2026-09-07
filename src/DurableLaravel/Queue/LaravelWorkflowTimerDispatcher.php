<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * A timer, on the delay the queue already carries.
 *
 * Waking an execution "in n milliseconds" is a deferred resume, and nothing else: the port does not
 * ask for a separate mechanism. The Symfony counterpart gets the same thing from a `DelayStamp`;
 * here it is `later()`, rounded **up** because waiting less than asked is the only error that
 * counts — a workflow woken too early resumes before its deadline.
 */
final class LaravelWorkflowTimerDispatcher implements WorkflowTimerDispatcher
{
    public function __construct(
        private readonly QueueFactory $queue,
        private readonly ?string $connection = null,
        private readonly ?string $queueName = null,
    ) {}

    public function dispatchTimerFire(string $executionId, int $delayMs = 0): void
    {
        $job = new ResumeWorkflowJob(new ResumeWorkflowMessage($executionId));
        $connection = $this->queue->connection($this->connection);

        if ($delayMs > 0) {
            $connection->later((int) ceil($delayMs / 1000), $job, '', $this->queueName);

            return;
        }

        $connection->push($job, '', $this->queueName);
    }
}
