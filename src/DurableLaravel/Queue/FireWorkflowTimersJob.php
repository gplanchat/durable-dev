<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A timer falling due, on the queue.
 *
 * Only {@see FireWorkflowTimersHandler} journals `TimerCompleted`: a plain resume replays the run,
 * finds the timer still pending and suspends on it again (#726). The handler resumes the run once
 * a timer has fired, and re-schedules itself when it was delivered early.
 *
 * It takes the same per-execution turn as a resume, like `SingleResumeLockMiddleware` on Symfony.
 * Firing is a pass (DUR053): unlocked, a duplicate firing would supersede the resume the first one
 * dispatched, find nothing left to fire, and leave the run asleep for good.
 *
 * ponytail: a taken turn puts the firing back after `durable.lock.backoff`, without the resume's
 * `max_deferrals` cap — the lock's TTL bounds a dead holder. Count deferrals here if a hot timer
 * ever needs that cap.
 */
final readonly class FireWorkflowTimersJob implements ShouldQueue
{
    public function __construct(
        public readonly FireWorkflowTimersMessage $message,
    ) {}

    public function handle(FireWorkflowTimersHandler $handler, ResumeLock $lock, WorkflowTimerDispatcher $timers, ResumeDeferral $deferral): void
    {
        $fired = $lock->tryAround($this->message->executionId, function () use ($handler): void {
            $handler($this->message);
        });

        if (!$fired) {
            $timers->dispatchTimerFire(ExecutionId::fromString($this->message->executionId), $deferral->backoffSeconds() * 1000);
        }
    }
}
