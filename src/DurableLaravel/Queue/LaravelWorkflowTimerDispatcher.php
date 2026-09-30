<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * A timer, on the delay the queue already carries.
 *
 * Waking an execution "in n milliseconds" is a deferred timer firing, not a plain resume: a resume
 * replays the run without completing the timer, and would suspend on it forever (#726). The Symfony
 * counterpart gets the delay from a `DelayStamp`; here it is `later()`, rounded **up** because
 * waiting less than asked is the only error that counts — a workflow woken too early resumes
 * before its deadline.
 */
final readonly class LaravelWorkflowTimerDispatcher implements WorkflowTimerDispatcher
{
    public function __construct(
        private readonly QueueFactory $queue,
        private readonly ?string $connection = null,
        private readonly ?string $queueName = null,
    ) {}

    public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void
    {
        $job = new FireWorkflowTimersJob(new FireWorkflowTimersMessage($executionId->toString()));
        $connection = $this->queue->connection($this->connection);

        if ($delayMs > 0) {
            $connection->later((int) ceil($delayMs / 1000), $job, '', $this->queueName);

            return;
        }

        $connection->push($job, '', $this->queueName);
    }
}
