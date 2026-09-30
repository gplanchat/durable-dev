<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\SystemClock;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Psr\Clock\ClockInterface;

/**
 * The memory backend's resumes and timers, driven in the caller's process (#603).
 *
 * The journal of this backend lives in the process, so nothing else can advance a run: the call that
 * starts it drives it. Resumes, the activities they queue and the timers that fall due drain in
 * one loop, one at a time. A dispatch made while the loop runs is only queued: a resume never
 * runs inside another, which is the recursion the provider refuses for a `sync` queue connection.
 *
 * A delayed retry or a timer is waited for within the budget; later work, or a run waiting on a
 * signal, stays suspended until the next dispatch. The budget is fixed on purpose: this backend
 * serves tests and local runs, not long waits. A handler that throws ends the drain; what was still
 * queued runs on the next dispatch in the same process, whatever run that dispatch is for.
 */
final class InProcessWorkflowResumeDispatcher implements WorkflowResumeDispatcher, WorkflowTimerDispatcher
{
    /** @var list<ResumeWorkflowMessage> */
    private array $resumes = [];

    /** @var list<array{at: float, message: FireWorkflowTimersMessage}> */
    private array $timers = [];

    private bool $draining = false;

    private readonly ClockInterface $clock;

    /**
     * @param \Closure(): (callable(ResumeWorkflowMessage): mixed)     $resume   the resume handler, resolved late: it takes this dispatcher
     * @param \Closure(): (callable(ActivityMessage): mixed)           $activity the activity processor, resolved late for the same reason
     * @param \Closure(): (callable(FireWorkflowTimersMessage): mixed) $fire     the timer handler, likewise
     * @param ClockInterface|null                                     $clock    the clock the activity transport stamps its
     *                                                                           due times with (`durable.clock`, #617); the
     *                                                                           core's system clock by default
     */
    public function __construct(
        private readonly WorkflowMetadataStore $metadata,
        private readonly ActivityTransportInterface $activities,
        private readonly \Closure $resume,
        private readonly \Closure $activity,
        private readonly \Closure $fire,
        private readonly float $budgetSeconds = 10.0,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
    {
        $this->resumes[] = new ResumeWorkflowMessage($executionId->toString(), $pendingUpdates);
        $this->drain();
    }

    /**
     * Nothing: the resume runs in this process, after the append, like a `sync` route (DUR050 §6).
     */
    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void {}

    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        // A caller passing `::class` gets the alias, as on the other dispatchers (#258).
        $this->metadata->save($executionId, (new WorkflowDefinitionLoader())->aliasForTemporalInterop($workflowType), $payload);
        $this->resumes[] = new ResumeWorkflowMessage($executionId->toString());
        $this->drain();
    }

    public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void
    {
        $this->timers[] = ['at' => $this->now() + (float) $delayMs / 1000.0, 'message' => new FireWorkflowTimersMessage($executionId->toString())];
        $this->drain();
    }

    private function drain(): void
    {
        if ($this->draining) {
            return;
        }
        $this->draining = true;
        $deadline = $this->now() + $this->budgetSeconds;
        // The budget is also a length of real time: a clock that does not move would never let
        // `$deadline` pass.
        $budgetEndsAt = hrtime(true) + (int) ($this->budgetSeconds * 1e9);

        try {
            while (true) {
                if ([] !== $this->resumes) {
                    (($this->resume)())(array_shift($this->resumes));

                    continue;
                }
                $activity = $this->activities->dequeue();
                if (null !== $activity) {
                    (($this->activity)())($activity);

                    continue;
                }
                $timer = $this->takeDueTimer();
                if (null !== $timer) {
                    (($this->fire)())($timer);

                    continue;
                }

                $next = $this->nextDueAt();
                if (null === $next || $next > $deadline || hrtime(true) >= $budgetEndsAt) {
                    return;
                }
                usleep((int) ceil(max(0.0, $next - $this->now()) * 1_000_000.0));
            }
        } finally {
            $this->draining = false;
        }
    }

    private function takeDueTimer(): ?FireWorkflowTimersMessage
    {
        $now = $this->now();
        foreach ($this->timers as $i => $timer) {
            if ($timer['at'] <= $now) {
                array_splice($this->timers, $i, 1);

                return $timer['message'];
            }
        }

        return null;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    private function nextDueAt(): ?float
    {
        $at = array_column($this->timers, 'at');
        $activity = $this->activities->nextDueAt();
        if (null !== $activity) {
            $at[] = $activity;
        }

        return [] === $at ? null : min($at);
    }
}
