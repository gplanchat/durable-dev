<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * What is done with a resume whose turn is taken: put it down again for later, a bounded number
 * of times.
 *
 * **The delay is a setting, and §1.5 says why.** On a cold execution (many workflows in flight, a
 * handful of workers), the collisions are a rounding error: 0.6% at sixteen executions per
 * worker. On a hot execution, the one a signal or a timer wakes ceaselessly, they rise to 98.8%,
 * and there the delay **is** the latency: one second of deferral turned 32 s of work into 148 s of
 * clock.
 *
 * **And the ceiling is noisy, not silent.** An endless deferral looks like an execution that is
 * making progress; an exception names the execution and the number of attempts, which shows up in
 * `failed_jobs`.
 */
final readonly class ResumeDeferral
{
    public function __construct(
        private readonly int $backoffSeconds = 1,
        private readonly int $maxDeferrals = 50,
        private readonly ?string $connection = null,
        private readonly ?string $queueName = null,
    ) {}

    public function defer(ResumeWorkflowJob $job, QueueFactory $queue): void
    {
        if ($job->deferrals >= $this->maxDeferrals) {
            throw new \RuntimeException(\sprintf(
                'Durable: gave up resuming %s: the per-execution lock was held on %d consecutive '
                . 'attempts. Either a worker died holding it (the lock TTL releases it), or this '
                . 'execution is resumed faster than it replays; raise durable.lock.backoff.',
                $job->message->executionId,
                $job->deferrals,
            ));
        }

        $this->putBack($job, $queue);
    }

    /**
     * Puts back a resume that arrived before its activity's outcome (DUR050). Out of deferrals, it
     * is dropped rather than failed: the activity worker sends another resume after the append, and
     * the error {@see defer()} raises would blame a lock nobody holds.
     */
    public function deferEarly(ResumeWorkflowJob $job, QueueFactory $queue): void
    {
        if ($job->deferrals < $this->maxDeferrals) {
            $this->putBack($job, $queue);
        }
    }

    /** How long a job whose turn is taken waits before it tries again: `durable.lock.backoff`. */
    public function backoffSeconds(): int
    {
        return $this->backoffSeconds;
    }

    private function putBack(ResumeWorkflowJob $job, QueueFactory $queue): void
    {
        $queue->connection($this->connection)->later(
            $this->backoffSeconds,
            new ResumeWorkflowJob($job->message, $job->deferrals + 1),
            '',
            $this->queueName,
        );
    }
}
