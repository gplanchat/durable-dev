<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Awaitable\AwaitableInspector;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * Two regressions from the move to the single activity path and the single fiber driver.
 */
final class DriverParityRegressionTest extends TestCase
{
    public function testDelayedRetriesAreExecutedByTheSynchronousDrain(): void
    {
        // isEmpty() only reports the absence of a *ready* message: looping on it concluded
        // "nothing left to do" and the retry policy did not apply at all.
        $runs = 0;
        $env = WorkflowTestEnvironment::inMemory([
            'flaky' => static function () use (&$runs): never {
                ++$runs;

                throw new \RuntimeException('boom');
            },
        ]);

        try {
            $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activityStub(
                SuiteActivities::class,
                new ActivityOptions(RetryLimit::ofAttempts(3), initialInterval: Duration::seconds(0.01)),
            )->flaky()), 'retry-1');
        } catch (\Throwable) {
        }

        self::assertSame(3, $runs, 'the delayed retries must be executed');
    }

    public function testDelayedRetryIsNotDequeuedBeforeItsDueTime(): void
    {
        // The backoff is genuinely respected: a retry is not consumed ahead of its due time.
        $transport = new InMemoryActivityTransport();
        $transport->enqueue(new \Gplanchat\Durable\Transport\ActivityMessage(
            'exec-1',
            'act-1',
            'later',
            [],
            retryDelay: Duration::seconds(30),
        ));

        self::assertNull($transport->dequeue());
        self::assertTrue($transport->isEmpty(), 'no ready message');
        self::assertNotNull($transport->nextDueAt(), 'but the queue is not empty for all that');
    }

    public function testARaceBetweenAnActivityAndATimerSchedulesATimerWake(): void
    {
        // Tested by a plain instanceof, an any(activity, timer) scheduled no wake at all: the
        // execution never restarted if the activity did not succeed.
        $eventStore = new InMemoryEventStore();
        $transport = new InMemoryActivityTransport();
        $executor = new RegistryActivityExecutor();
        $executor->register('slow', static fn(): string => 'unused');
        $engine = new ExecutionEngine(
            $eventStore,
            new ExecutionRuntime($eventStore, $transport, $executor, 0, null, true),
        );

        try {
            $engine->start('race-1', static fn(WorkflowEnvironment $env): mixed => $env->await($env->any(
                $env->activityStub(SuiteActivities::class)->slow(),
                $env->timer(3600.0),
            )));
            self::fail('the workflow was to suspend');
        } catch (WorkflowSuspendedException $e) {
            self::assertTrue($e->waitingOnTimer(), 'the race carries a deadline to wake');
            self::assertTrue($e->shouldDispatchResume());
        }
    }

    public function testSleepWaitsWhileTimerComposes(): void
    {
        // Two neighbouring methods of the same facade had opposite contracts: activity()
        // returned an awaitable, timer() waited on the spot and returned void. The names now say
        // which one does what.
        $env = WorkflowTestEnvironment::inMemory(['echo' => static fn(): string => 'done']);

        $result = $env->run(static function (WorkflowEnvironment $wf): array {
            $composable = $wf->timer(0.0);
            $wf->sleep(0.0);

            return [$composable instanceof \Gplanchat\Durable\Awaitable\Awaitable, $wf->await($wf->activityStub(SuiteActivities::class)->echoValue())];
        }, 'sleep-1');

        self::assertSame([true, 'done'], $result);
    }

    public function testTimerAcceptsEveryDurationForm(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        $result = $env->run(static function (WorkflowEnvironment $wf): string {
            $wf->sleep(30.0);
            $wf->sleep(Duration::minutes(5));
            $wf->sleep(new \DateInterval('PT2H'));

            return 'ok';
        }, 'sleep-2');

        self::assertSame('ok', $result);
    }

    public function testTheHarnessSkipsTimeInsteadOfWaitingForIt(): void
    {
        // Without a clock skip, no sleeping workflow is testable: the harness has nobody to
        // deliver a timer wake to it.
        $env = WorkflowTestEnvironment::inMemory(['ping' => static fn(): string => 'pong']);

        $startedAt = microtime(true);
        $result = $env->run(static function (WorkflowEnvironment $wf): string {
            $wf->sleep(Duration::hours(1));
            $answer = $wf->await($wf->activityStub(SuiteActivities::class)->ping());
            $wf->sleep(Duration::hours(24));

            return $answer;
        }, 'skip-1');

        self::assertSame('pong', $result);
        self::assertLessThan(1.0, microtime(true) - $startedAt, '25 hours of sleep must cost no real time');
    }

    public function testTimeIsNotSkippedWhileAnActivityCanStillWin(): void
    {
        // The skip must only happen once nothing else progresses: skipping earlier would make
        // the timer win any race an activity was in the middle of winning.
        $env = WorkflowTestEnvironment::inMemory(['fast' => static fn(): string => 'winner']);

        $result = $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->any(
            $wf->activityStub(SuiteActivities::class)->fast(),
            $wf->timer(Duration::hours(1)),
        )), 'race-skip');

        self::assertSame('winner', $result);
    }

    public function testTimerWaitDetectionTraversesComposites(): void
    {
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        $activity = new \Gplanchat\Durable\Awaitable\ActivityAwaitable($deferred->awaitable(), 'act-1');
        $timer = new \Gplanchat\Durable\Awaitable\TimerAwaitable($deferred->awaitable(), 'timer-1');

        self::assertFalse(AwaitableInspector::waitsOnTimer($activity));
        self::assertTrue(AwaitableInspector::waitsOnTimer($timer));
        self::assertTrue(AwaitableInspector::waitsOnTimer(
            new \Gplanchat\Durable\Awaitable\AnyAwaitable([$activity, $timer]),
        ));
        self::assertFalse(AwaitableInspector::waitsOnTimer(
            new \Gplanchat\Durable\Awaitable\AnyAwaitable([$activity, $activity]),
        ));
    }

    public function testTheHarnessReportsAnActivityThatRetriesForever(): void
    {
        // Attempts now being unlimited by default, the harness must fail with an actionable
        // message instead of running forever.
        $env = WorkflowTestEnvironment::inMemory(
            ['always' => static function (): never {
                throw new \RuntimeException('boom');
            }],
            budgetSeconds: 0.5,
        );

        $this->expectException(WorkflowStuckException::class);
        $this->expectExceptionMessageMatches('/retry indefinitely by default/');

        $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activityStub(SuiteActivities::class, new ActivityOptions(initialInterval: Duration::seconds(0.05)))->always()),
            'runaway-1',
        );
    }

    public function testSyncDrainStillCompletesASimpleActivity(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['echo' => static fn(array $p): mixed => $p['v']]);

        self::assertSame(42, $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activityStub(SuiteActivities::class)->echoValue(42)),
            'plain-1',
        ));
        $completed = null;
        foreach ($env->getEventStore()->readStream('plain-1') as $event) {
            if ($event instanceof ActivityCompleted) {
                $completed = $event;
            }
        }
        self::assertSame(42, $completed?->result());
    }
}
