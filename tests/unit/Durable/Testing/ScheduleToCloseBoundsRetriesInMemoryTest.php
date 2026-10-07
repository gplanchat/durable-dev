<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The in-memory runner's time stands still between timer skips, but a retry's backoff is waited
 * out for real: that wait counts towards schedule-to-close, or the bound never trips (#617).
 */
final class ScheduleToCloseBoundsRetriesInMemoryTest extends TestCase
{
    public function testTheBackoffWaitedOutCountsTowardsScheduleToClose(): void
    {
        $attempts = 0;
        $env = WorkflowTestEnvironment::inMemory(['flaky' => static function () use (&$attempts): never {
            ++$attempts;

            throw new \RuntimeException('boom');
        }]);

        try {
            $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activityStub(FlakyActivities::class, new ActivityOptions(
                retryLimit: RetryLimit::ofAttempts(8),
                initialInterval: Duration::seconds(0.2),
                backoffCoefficient: 1.0,
                timeouts: new ActivityTimeouts(scheduleToClose: Duration::seconds(0.3)),
            ))->flaky()));
            self::fail('the activity never succeeds');
        } catch (\Throwable $e) {
            // The attempt's own failure ends the activity, as on Temporal (#978).
            self::assertStringContainsString('boom', $e->getMessage());
        }
        self::assertSame(2, $attempts, 'the third attempt would start past the 0.3 s bound');
    }

    /**
     * A timer that falls due during a backoff fires before the retry and settles `any()`, as on
     * Temporal: the retrying activity loses and is cancelled (#653). It used to run every
     * remaining attempt first and win.
     */
    public function testATimerDueDuringABackoffWinsBeforeTheRetry(): void
    {
        $attempts = 0;
        $env = WorkflowTestEnvironment::inMemory(['flaky' => static function () use (&$attempts): string {
            if (++$attempts < 3) {
                throw new \RuntimeException('boom');
            }

            return 'charged';
        }]);

        $result = $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->any(
            $wf->activityStub(FlakyActivities::class, new ActivityOptions(
                retryLimit: RetryLimit::ofAttempts(5),
                initialInterval: Duration::seconds(0.2),
                backoffCoefficient: 1.0,
            ))->flaky(),
            $wf->timer(0.1),
        )), 'exec-race');

        self::assertNull($result, 'the timer wins any()');
        self::assertSame(1, $attempts, 'the 0.1 s timer falls due during the first 0.2 s backoff');
        self::assertSame(['TimerCompleted', 'ActivityCancelled'], self::raceOutcomes($env, 'exec-race'));
        self::assertNull($env->getActivityTransport()->nextDueAt(), 'the loser\'s retry is no longer queued');
    }

    /**
     * The virtual clock does not move while an attempt runs: a timer shorter than a slow attempt
     * that succeeds does not win.
     */
    public function testATimerShorterThanARunningAttemptDoesNotWin(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['quick' => static function (): string {
            usleep(200_000);

            return 'slow but first';
        }]);

        $result = $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->any(
            $wf->activityStub(FlakyActivities::class)->quick(),
            $wf->timer(0.1),
        )), 'exec-slow');

        self::assertSame('slow but first', $result);
        // Had the virtual clock followed the attempt, the timer would be recorded as fired.
        self::assertSame(['ActivityCompleted', 'TimerCancelled'], self::raceOutcomes($env, 'exec-slow'));
    }

    /**
     * The race is settled mid-backoff, and the workflow goes on: the lost activity must not
     * surface as an unhandled failure when the next resume replays the race (#678).
     */
    public function testTheWorkflowGoesOnAfterATimerWinsDuringABackoff(): void
    {
        $attempts = 0;
        $env = WorkflowTestEnvironment::inMemory(['flaky' => static function () use (&$attempts): string {
            if (++$attempts < 3) {
                throw new \RuntimeException('boom');
            }

            return 'charged';
        }]);

        $result = $env->run(static function (WorkflowEnvironment $wf): string {
            $wf->await($wf->any(
                $wf->activityStub(FlakyActivities::class, new ActivityOptions(
                    retryLimit: RetryLimit::ofAttempts(5),
                    initialInterval: Duration::seconds(0.2),
                    backoffCoefficient: 1.0,
                ))->flaky(),
                $wf->timer(0.1),
            ));
            $wf->await($wf->timer(1));

            return 'after';
        }, 'exec-after');

        self::assertSame('after', $result);
        self::assertSame(1, $attempts);
        self::assertSame(['TimerCompleted', 'ActivityCancelled', 'TimerCompleted'], self::raceOutcomes($env, 'exec-after'));
    }

    /**
     * @return list<string>
     */
    private static function raceOutcomes(WorkflowTestEnvironment $env, string $executionId): array
    {
        $recorded = [];
        foreach ($env->getEventStore()->readStream(ExecutionId::fromString($executionId)) as $event) {
            $name = (new \ReflectionClass($event))->getShortName();
            if (\in_array($name, ['ActivityCompleted', 'ActivityCancelled', 'TimerCompleted', 'TimerCancelled'], true)) {
                $recorded[] = $name;
            }
        }

        return $recorded;
    }
}

interface FlakyActivities
{
    #[AsActivityMethod('flaky')]
    public function flaky(): string;

    #[AsActivityMethod('quick')]
    public function quick(): string;
}
