<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Exception\DeadlineExceededException;
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
 * Bounding a wait in time from workflow code.
 *
 * The verdict cases are played on a journal written by hand then replayed: it is the only way to
 * reach the event order that counts (signal recorded after the deadline fired, and the other way
 * round), and it is the replay path the verdict must cross.
 */
final class WorkflowDeadlineTest extends TestCase
{
    // -------------------------------------------------------------------------
    // The verdict
    // -------------------------------------------------------------------------

    public function testWorkThatSettlesBeforeItsDeadlineReturnsItsValue(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['fast' => static fn(): string => 'answer']);

        $result = $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activityStub(SuiteActivities::class)->fast(), Duration::hours(1)),
            'deadline-1',
        );

        self::assertSame('answer', $result);
        self::assertCount(1, $this->eventsOf($env->getEventStore(), 'deadline-1', TimerCancelled::class));
        self::assertSame([], $this->eventsOf($env->getEventStore(), 'deadline-1', TimerCompleted::class));
    }

    public function testAnEmptyAnswerIsNotADeadline(): void
    {
        // The case a hand-written race cannot express: any() returns the winning value, and
        // `null` is indistinguishable there from an elapsed deadline.
        $env = WorkflowTestEnvironment::inMemory(['empty' => static fn(): mixed => null]);

        $result = $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activityStub(SuiteActivities::class)->emptyResult(), Duration::hours(1)),
            'deadline-2',
        );

        self::assertNull($result);
    }

    public function testAnElapsedDeadlineRaisesADeadlineFailure(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        $result = $env->run(static function (WorkflowEnvironment $wf): string {
            try {
                $wf->await($wf->timer(Duration::hours(2)), Duration::seconds(30));

                return 'settled';
            } catch (DeadlineExceededException $e) {
                return 'expired after ' . $e->deadline()->toSeconds() . 's';
            }
        }, 'deadline-3');

        self::assertSame('expired after 30s', $result);
    }

    public function testASignalWaitGivesUpOnItsDeadlineInTheHarness(): void
    {
        // The first test anyone using the feature will write: nobody delivers the signal, and it
        // is the deadline that must conclude — not the runner's no-progress guard.
        $env = WorkflowTestEnvironment::inMemory([]);

        $result = $env->run(static function (WorkflowEnvironment $wf): string {
            $approvals = [];
            $wf->onSignal('approve', static function (array $payload) use (&$approvals): void {
                $approvals[] = $payload;
            });

            try {
                $wf->await(static function () use (&$approvals): bool {
                    return [] !== $approvals;
                }, Duration::hours(1));

                return 'approved';
            } catch (DeadlineExceededException) {
                return 'expired';
            }
        }, 'deadline-11');

        self::assertSame('expired', $result);
    }

    // -------------------------------------------------------------------------
    // The deadline cancels what it was bounding
    // -------------------------------------------------------------------------

    public function testTheBoundedActivityIsCancelledWhenTheDeadlineElapses(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $handler = static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activityStub(SuiteActivities::class)->slow(), Duration::seconds(30));

        try {
            $engine->start('deadline-4', $handler);
            self::fail('the workflow was to suspend');
        } catch (WorkflowSuspendedException) {
        }

        $timerId = $this->firstTimerId($store, 'deadline-4');
        $store->append(new TimerCompleted('deadline-4', $timerId));

        try {
            $engine->resume('deadline-4', $handler);
            self::fail('the deadline was to raise');
        } catch (DeadlineExceededException) {
        }

        self::assertCount(1, $this->eventsOf($store, 'deadline-4', ActivityCancelled::class));
    }

    public function testAnActivityCompletingAfterItsDeadlineDoesNotResumeTheWorkflow(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $handler = static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activityStub(SuiteActivities::class)->slow(), Duration::seconds(30));

        try {
            $engine->start('deadline-5', $handler);
        } catch (WorkflowSuspendedException) {
        }
        $store->append(new TimerCompleted('deadline-5', $this->firstTimerId($store, 'deadline-5')));

        try {
            $engine->resume('deadline-5', $handler);
        } catch (DeadlineExceededException) {
        }

        // The cancelled activity answers anyway: the verdict does not move.
        $activityId = null;
        foreach ($store->readStream('deadline-5') as $event) {
            if ($event instanceof ActivityScheduled) {
                $activityId = $event->activityId();
            }
        }
        self::assertNotNull($activityId);
        $store->append(new ActivityCompleted('deadline-5', $activityId, 'late'));

        $this->expectException(DeadlineExceededException::class);
        $engine->resume('deadline-5', $handler);
    }

    // -------------------------------------------------------------------------
    // The verdict comes from the journal, not from the replay clock
    // -------------------------------------------------------------------------

    public function testReplayOfATimedOutExecutionSchedulesNothingNew(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $handler = $this->signalHandler();

        $store->append(new ExecutionStarted('deadline-6', []));
        $store->append(new TimerScheduled('deadline-6', 'timer-a', 0.0));
        $store->append(new TimerCompleted('deadline-6', 'timer-a'));

        self::assertSame(['timeout'], $engine->resume('deadline-6', $handler));
        self::assertCount(1, $this->eventsOf($store, 'deadline-6', TimerScheduled::class));
    }

    public function testASignalDeliveredAfterItsDeadlineDoesNotUndoTheTimeout(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $handler = $this->signalHandler();

        $store->append(new ExecutionStarted('deadline-7', []));
        $store->append(new TimerScheduled('deadline-7', 'timer-a', 0.0));
        $store->append(new TimerCompleted('deadline-7', 'timer-a'));
        $store->append(new WorkflowSignalReceived('deadline-7', 'approve', ['by' => 'late']));

        self::assertSame(['timeout'], $engine->resume('deadline-7', $handler));
        self::assertSame(['timeout'], $engine->resume('deadline-7', $handler));
    }

    public function testASignalRecordedBeforeTheDeadlineFiredStillSettlesTheWait(): void
    {
        // Both branches are settled in the history and neither has been cancelled: the verdict
        // can therefore not come from the declaration order of the branches, only from the order
        // of the journal.
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $handler = $this->signalHandler();

        $store->append(new ExecutionStarted('deadline-8', []));
        $store->append(new TimerScheduled('deadline-8', 'timer-a', 0.0));
        $store->append(new WorkflowSignalReceived('deadline-8', 'approve', ['by' => 'alice']));
        $store->append(new TimerCompleted('deadline-8', 'timer-a'));

        self::assertSame(['signal', ['by' => 'alice']], $engine->resume('deadline-8', $handler));
    }

    public function testALateSignalRemainsAvailableToALaterWait(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $handler = static function (WorkflowEnvironment $wf): array {
            $approvals = [];
            $wf->onSignal('approve', static function (array $payload) use (&$approvals): void {
                $approvals[] = $payload;
            });
            $pending = static function () use (&$approvals): bool {
                return [] !== $approvals;
            };

            try {
                $wf->await($pending, Duration::seconds(30));

                return ['unexpected'];
            } catch (DeadlineExceededException) {
                // The late signal was not applied to the wait the deadline decided: the next one
                // observes it.
                $wf->await($pending);

                return ['second wait', array_shift($approvals)];
            }
        };

        $store->append(new ExecutionStarted('deadline-9', []));
        $store->append(new TimerScheduled('deadline-9', 'timer-a', 0.0));
        $store->append(new TimerCompleted('deadline-9', 'timer-a'));
        $store->append(new WorkflowSignalReceived('deadline-9', 'approve', ['by' => 'bob']));

        self::assertSame(['second wait', ['by' => 'bob']], $engine->resume('deadline-9', $handler));
    }

    public function testASignalArrivingBeforeItsDeadlineIsReturned(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('deadline-10', []));
        $store->append(new WorkflowSignalReceived('deadline-10', 'approve', ['by' => 'carol']));

        self::assertSame(['signal', ['by' => 'carol']], $engine->resume('deadline-10', $this->signalHandler()));
    }

    // -------------------------------------------------------------------------

    /**
     * @return callable(WorkflowEnvironment): array<int, mixed>
     */
    private function signalHandler(): callable
    {
        return static function (WorkflowEnvironment $wf): array {
            $approvals = [];
            $wf->onSignal('approve', static function (array $payload) use (&$approvals): void {
                $approvals[] = $payload;
            });

            try {
                $wf->await(static function () use (&$approvals): bool {
                    return [] !== $approvals;
                }, Duration::seconds(30));

                return ['signal', array_shift($approvals)];
            } catch (DeadlineExceededException) {
                return ['timeout'];
            }
        };
    }

    private function engine(InMemoryEventStore $store): ExecutionEngine
    {
        $executor = new RegistryActivityExecutor();
        $executor->register('slow', static fn(): string => 'never reached in these tests');

        return new ExecutionEngine(
            $store,
            new ExecutionRuntime($store, new InMemoryActivityTransport(), $executor, 0, null, true),
        );
    }

    private function firstTimerId(InMemoryEventStore $store, string $executionId): string
    {
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof TimerScheduled) {
                return $event->timerId();
            }
        }

        self::fail('no timer scheduled');
    }

    /**
     * @param class-string $class
     *
     * @return list<object>
     */
    private function eventsOf(InMemoryEventStore $store, string $executionId, string $class): array
    {
        $out = [];
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof $class) {
                $out[] = $event;
            }
        }

        return $out;
    }
}
