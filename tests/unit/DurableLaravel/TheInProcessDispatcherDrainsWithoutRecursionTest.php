<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Laravel\Queue\InProcessWorkflowResumeDispatcher;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\FrozenClock;

/**
 * #603: on Laravel's memory backend a run is driven in the caller's process. A resume dispatched
 * while a resume runs is queued and run after it, never inside it.
 */
final class TheInProcessDispatcherDrainsWithoutRecursionTest extends TestCase
{
    private ?InProcessWorkflowResumeDispatcher $subject = null;
    private int $depth = 0;
    private int $deepest = 0;

    /** @var list<string> */
    private array $ran = [];

    public function testAResumeDispatchedDuringAResumeRunsAfterItNotInsideIt(): void
    {
        $dispatcher = $this->subject = $this->dispatcher(resume: function (ResumeWorkflowMessage $message): void {
            $this->enter('resume ' . $message->executionId);
            if ('exec-1' === $message->executionId) {
                $this->subject?->dispatchResume(ExecutionId::fromString('exec-2'));
            }
            --$this->depth;
        });

        $dispatcher->dispatchNewWorkflowRun(ExecutionId::fromString('exec-1'), 'Greeting', []);

        self::assertSame(['resume exec-1', 'resume exec-2'], $this->ran);
        self::assertSame(1, $this->deepest, 'no resume ran inside another');
    }

    public function testAnActivityQueuedByAResumeRunsAndItsResumeFollows(): void
    {
        $activities = new InMemoryActivityTransport();
        $dispatcher = $this->subject = $this->dispatcher(
            resume: function (ResumeWorkflowMessage $message) use ($activities): void {
                $this->ran[] = 'resume ' . $message->executionId;
                if (1 === \count($this->ran)) {
                    $activities->enqueue(new ActivityMessage($message->executionId, 'act-1', 'charge', []));
                }
            },
            activity: function (ActivityMessage $message): void {
                $this->ran[] = 'activity ' . $message->activityId;
                $this->subject?->dispatchResume(ExecutionId::fromString($message->executionId));
            },
            activities: $activities,
        );

        $dispatcher->dispatchNewWorkflowRun(ExecutionId::fromString('exec-1'), 'Greeting', []);

        self::assertSame(['resume exec-1', 'activity act-1', 'resume exec-1'], $this->ran);
    }

    public function testAFailedResumeLeavesTheDispatcherReadyForTheNextOne(): void
    {
        $dispatcher = $this->dispatcher(resume: function (ResumeWorkflowMessage $message): void {
            $this->ran[] = 'resume ' . $message->executionId;
            if ('exec-1' === $message->executionId) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $dispatcher->dispatchResume(ExecutionId::fromString('exec-1'));
            self::fail('the failure reaches the caller');
        } catch (\RuntimeException) {
        }
        $dispatcher->dispatchResume(ExecutionId::fromString('exec-2'));

        self::assertSame(['resume exec-1', 'resume exec-2'], $this->ran, 'the second dispatch still drains');
    }

    public function testATimerDueWithinTheBudgetFiresInTheSameCall(): void
    {
        $dispatcher = $this->dispatcher(fire: function (FireWorkflowTimersMessage $message): void {
            $this->ran[] = 'fire ' . $message->executionId;
        });

        $dispatcher->dispatchTimerFire(ExecutionId::fromString('exec-1'), 50);

        self::assertSame(['fire exec-1'], $this->ran);
    }

    /**
     * A timer due past the budget is not waited for: the run stays suspended, as it does on a
     * signal, and the next dispatch fires what has come due.
     */
    public function testATimerDuePastTheBudgetIsLeftForALaterDrain(): void
    {
        $dispatcher = $this->dispatcher(fire: function (FireWorkflowTimersMessage $message): void {
            $this->ran[] = 'fire ' . $message->executionId;
        }, budget: 0.05);

        $dispatcher->dispatchTimerFire(ExecutionId::fromString('exec-1'), 60_000);

        self::assertSame([], $this->ran);
    }

    private function enter(string $what): void
    {
        $this->ran[] = $what;
        $this->deepest = max($this->deepest, ++$this->depth);
    }

    /**
     * The transport stamps its due times with `durable.clock`, and the drain waits for them on the
     * same clock. A clock that does not move never makes a retry due: the budget, a length of real
     * time, still ends the drain (#617).
     */
    public function testTheDrainWaitsOnTheTransportsClockWithinARealBudget(): void
    {
        $clock = new FrozenClock(1_700_000_000.0);
        $activities = new InMemoryActivityTransport($clock);
        $activities->enqueue(new ActivityMessage('exec-1', 'act-1', 'charge', [], retryDelay: Duration::seconds(0.05)));
        $dispatcher = new InProcessWorkflowResumeDispatcher(
            new InMemoryWorkflowMetadataStore(),
            $activities,
            static fn(): \Closure => static function (): void {},
            fn(): \Closure => function (ActivityMessage $message): void {
                $this->ran[] = 'activity ' . $message->activityId;
            },
            static fn(): \Closure => static function (): void {},
            0.3,
            clock: $clock,
        );

        $started = hrtime(true);
        $dispatcher->dispatchResume(ExecutionId::fromString('exec-1'));

        self::assertSame([], $this->ran, 'the retry is not due on the frozen clock');
        self::assertLessThan(2.0, ((float) (hrtime(true) - $started)) / 1e9);
    }

    private function dispatcher(
        ?\Closure $resume = null,
        ?\Closure $activity = null,
        ?\Closure $fire = null,
        ?InMemoryActivityTransport $activities = null,
        float $budget = 2.0,
    ): InProcessWorkflowResumeDispatcher {
        $none = static function (): void {};

        return new InProcessWorkflowResumeDispatcher(
            new InMemoryWorkflowMetadataStore(),
            $activities ?? new InMemoryActivityTransport(),
            static fn(): \Closure => $resume ?? $none,
            static fn(): \Closure => $activity ?? $none,
            static fn(): \Closure => $fire ?? $none,
            $budget,
        );
    }
}
