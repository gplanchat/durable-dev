<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Waiting on a condition over the workflow state — block 2 of the change
 * workflow-conditions-and-handler-dispatch.
 *
 * The verdict cases are played on a journal written by hand then replayed: it is the only way to
 * reach the event order that counts, and it is the replay path the verdict must cross.
 *
 * Note for reviewers: these tests are RED by construction. `await(condition)` and `onSignal()` do
 * not exist yet — they arrive at blocks 4 and 5. This file fixes the intended public shape, not a
 * regression.
 */
final class WorkflowConditionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // 2.1 — a condition that already holds
    // -------------------------------------------------------------------------

    public function testAConditionThatAlreadyHoldsDoesNotSuspend(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        $result = $env->run(static function (WorkflowEnvironment $wf): string {
            $wf->await(static fn(): bool => true);

            return 'passé sans suspendre';
        }, 'cond-1');

        self::assertSame('passé sans suspendre', $result);
        // Nothing that could wake the execution later: no guard timer scheduled.
        self::assertSame([], $this->eventsOf($env->getEventStore(), 'cond-1', TimerScheduled::class));
    }

    // -------------------------------------------------------------------------
    // 2.2 / 2.3 — a message makes the condition true, and replay restarts at the same place
    // -------------------------------------------------------------------------

    public function testAConditionBecomesTrueOnADeliveredMessage(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('cond-2', []));
        $store->append(new WorkflowSignalReceived('cond-2', 'tick', ['n' => 1]));

        self::assertSame([['n' => 1]], $engine->resume('cond-2', $this->tickHandler(1)));
    }

    public function testReplayReachesTheSameStateAndSchedulesNothingNew(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $handler = $this->tickHandler(1);

        $store->append(new ExecutionStarted('cond-3', []));
        $store->append(new WorkflowSignalReceived('cond-3', 'tick', ['n' => 1]));

        $first = $engine->resume('cond-3', $handler);
        $second = $engine->resume('cond-3', $handler);

        self::assertSame($first, $second);
        // "nothing new" bears on the scheduled work, not on the whole journal: replaying an
        // already closed execution rewrites its closure there, and that is true of every replay,
        // condition or not.
        self::assertSame([], $this->eventsOf($store, 'cond-3', TimerScheduled::class));
        self::assertCount(1, $this->eventsOf($store, 'cond-3', WorkflowSignalReceived::class));
    }

    // -------------------------------------------------------------------------
    // 2.4 — the DUR032 guarantee, restated on a condition
    // -------------------------------------------------------------------------

    public function testAMessageRecordedAfterTheDeadlineDoesNotUndoTheTimeout(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('cond-4', []));
        $store->append(new TimerScheduled('cond-4', 'timer-a', 0.0));
        $store->append(new TimerCompleted('cond-4', 'timer-a'));
        $store->append(new WorkflowSignalReceived('cond-4', 'tick', ['n' => 1]));

        self::assertSame(['expiré'], $engine->resume('cond-4', $this->boundedTickHandler()));
        self::assertSame(['expiré'], $engine->resume('cond-4', $this->boundedTickHandler()), 'stable on replay');
    }

    public function testAMessageRecordedBeforeTheDeadlineStillSatisfiesTheCondition(): void
    {
        // Both branches are settled in the history and neither is cancelled: only the order of
        // the journal can separate them.
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('cond-5', []));
        $store->append(new TimerScheduled('cond-5', 'timer-a', 0.0));
        $store->append(new WorkflowSignalReceived('cond-5', 'tick', ['n' => 1]));
        $store->append(new TimerCompleted('cond-5', 'timer-a'));

        self::assertSame(['satisfait', ['n' => 1]], $engine->resume('cond-5', $this->boundedTickHandler()));
    }

    // -------------------------------------------------------------------------
    // 2.5 — messages are applied one at a time
    // -------------------------------------------------------------------------

    public function testTwoMessagesAreAppliedOneAtATime(): void
    {
        // The discriminating test for interleaving: two messages each satisfy the condition.
        // Applied as one block, the workflow would resume seeing two of them; one at a time, it
        // resumes on the first.
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);

        $store->append(new ExecutionStarted('cond-6', []));
        $store->append(new WorkflowSignalReceived('cond-6', 'tick', ['n' => 1]));
        $store->append(new WorkflowSignalReceived('cond-6', 'tick', ['n' => 2]));

        $seen = $engine->resume('cond-6', static function (WorkflowEnvironment $wf): array {
            $ticks = [];
            $wf->onSignal('tick', static function (array $payload) use (&$ticks): void {
                $ticks[] = $payload;
            });

            // `fn()` captures by value: a condition over a local variable must go through
            // `use (&$…)`, failing which it reads the initial state back forever.
            $wf->await(static function () use (&$ticks): bool {
                return [] !== $ticks;
            });

            return ['au réveil' => \count($ticks)];
        });

        self::assertSame(['au réveil' => 1], $seen);
    }

    // -------------------------------------------------------------------------
    // 2.6 — a condition that can never hold
    // -------------------------------------------------------------------------

    public function testAConditionThatCanNeverHoldIsReportedNotHung(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        try {
            $env->run(static function (WorkflowEnvironment $wf): never {
                $wf->await(static fn(): bool => false);

                throw new \LogicException('inatteignable');
            }, 'cond-7');
            self::fail('the execution was to be reported as unable to advance');
        } catch (WorkflowStuckException $e) {
            // "by naming the condition": its position is enough to find it again, and adds no
            // parameter to the API.
            self::assertStringContainsString('WorkflowConditionTest.php', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // 2.7 — a non-reproducible value is recorded before it is read
    // -------------------------------------------------------------------------

    public function testANonReproducibleValueIsReadBackIdenticallyOnReplay(): void
    {
        $draws = 0;
        $env = WorkflowTestEnvironment::inMemory([]);
        $handler = static function (WorkflowEnvironment $wf) use (&$draws): int {
            $threshold = $wf->sideEffect(static function () use (&$draws): int {
                ++$draws;

                return 7;
            });

            $wf->await(static fn(): bool => $threshold > 0);

            return $threshold;
        };

        self::assertSame(7, $env->run($handler, 'cond-8'));
        self::assertSame(7, $env->run($handler, 'cond-8'), 'the replay reads the recorded value back');
        self::assertSame(1, $draws, 'the non-reproducible closure is evaluated only once');
    }

    // -------------------------------------------------------------------------

    /**
     * Workflow: accumulates the `tick` signals through a handler, and waits until it has enough.
     *
     * @return callable(WorkflowEnvironment): array<int, mixed>
     */
    private function tickHandler(int $expected): callable
    {
        return static function (WorkflowEnvironment $wf) use ($expected): array {
            $ticks = [];
            $wf->onSignal('tick', static function (array $payload) use (&$ticks): void {
                $ticks[] = $payload;
            });

            $wf->await(static function () use (&$ticks, $expected): bool {
                return \count($ticks) >= $expected;
            });

            return $ticks;
        };
    }

    /**
     * The same one, under a deadline: either the condition wins, or the deadline does.
     *
     * @return callable(WorkflowEnvironment): array<int, mixed>
     */
    private function boundedTickHandler(): callable
    {
        return static function (WorkflowEnvironment $wf): array {
            $ticks = [];
            $wf->onSignal('tick', static function (array $payload) use (&$ticks): void {
                $ticks[] = $payload;
            });

            try {
                $wf->await(static function () use (&$ticks): bool {
                    return [] !== $ticks;
                }, Duration::seconds(30));

                return ['satisfait', $ticks[0]];
            } catch (DeadlineExceededException) {
                return ['expiré'];
            }
        };
    }

    private function engine(InMemoryEventStore $store): ExecutionEngine
    {
        return new ExecutionEngine(
            $store,
            new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
        );
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
