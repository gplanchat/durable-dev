<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * The assemblers return an {@see Awaitable}: that is what makes it possible to bound them with a
 * deadline and to nest them. An assembler that waited on your behalf could do neither.
 */
final class AwaitableCompositionTest extends TestCase
{
    public function testAnAssembledWaitCanItselfBeBounded(): void
    {
        // Unwritable as long as all() returned an array: the value was already there by the time
        // one would have wanted to bound it.
        $env = WorkflowTestEnvironment::inMemory([
            'fast' => static fn(): string => 'a',
            'slow' => static fn(): string => 'b',
        ]);

        $result = $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await(
                $wf->all($wf->activityStub(SuiteActivities::class)->fast(), $wf->activityStub(SuiteActivities::class)->slow()),
                Duration::hours(1),
            ),
            'compose-1',
        );

        self::assertSame(['a', 'b'], $result);
    }

    public function testAssemblersNest(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'a' => static fn(): string => 'a',
            'b' => static fn(): string => 'b',
            'c' => static fn(): string => 'c',
        ]);

        $result = $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->all(
                $wf->activityStub(SuiteActivities::class)->a(),
                $wf->any($wf->activityStub(SuiteActivities::class)->b(), $wf->activityStub(SuiteActivities::class)->c()),
            )),
            'compose-2',
        );

        self::assertIsArray($result);
        self::assertSame('a', $result[0]);
        self::assertContains($result[1], ['b', 'c']);
    }

    public function testANestedRaceCancelsItsLoserExactlyOnce(): void
    {
        // isSettled() is queried in a loop by the engine. If a quorum's tally unwrapped its
        // members, the nested race would announce its loser on every engine turn.
        $env = WorkflowTestEnvironment::inMemory(['fast' => static fn(): string => 'winner']);
        $store = $env->getEventStore();

        $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->all(
                $wf->activityStub(SuiteActivities::class)->fast(),
                $wf->any($wf->activityStub(SuiteActivities::class)->fast(), $wf->timer(Duration::hours(1))),
            )),
            'nested-1',
        );

        self::assertCount(1, $this->cancelledTimers($store, 'nested-1'));
    }

    public function testAQuorumCancelsTheMembersStillRunningWhenItFalls(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['fast' => static fn(): string => 'winner']);
        $store = $env->getEventStore();

        $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->some(
                1,
                $wf->activityStub(SuiteActivities::class)->fast(),
                $wf->timer(Duration::hours(1)),
                $wf->timer(Duration::hours(2)),
            )),
            'quorum-6',
        );

        self::assertCount(2, $this->cancelledTimers($store, 'quorum-6'));
    }

    public function testADeadlineOnAnAssemblyReachesTheActivitiesInsideIt(): void
    {
        // The cancellation walk must descend into the composite: stopping at the first level
        // left both activities in the queue, out of reach of the deadline.
        $env = WorkflowTestEnvironment::inMemory([
            'never' => static fn(): string => 'unreachable',
        ]);
        $store = $env->getEventStore();

        try {
            $env->run(
                static fn(WorkflowEnvironment $wf): mixed => $wf->await(
                    $wf->all($wf->timer(Duration::hours(1)), $wf->timer(Duration::hours(2))),
                    Duration::seconds(0.0),
                ),
                'compose-3',
            );
            self::fail('the deadline was to win');
        } catch (DeadlineExceededException) {
            // expected
        }

        $cancelled = $this->cancelledTimers($store, 'compose-3');

        // The two bounded timers, and them alone: the deadline's own one has fired, there is
        // nothing left to withdraw there. Before the cancellation walk descended into the
        // composite, neither of the two was reached and both stayed in the queue.
        self::assertCount(2, $cancelled, 'the inner branches must be withdrawn from the queue');
    }

    // -------------------------------------------------------------------------
    // some(): the quorum
    // -------------------------------------------------------------------------

    public function testAQuorumSettlesOnTheCountAskedFor(): void
    {
        $env = WorkflowTestEnvironment::inMemory([
            'price' => static fn(array $p): string => 'quote-' . ($p['n'] ?? '?'),
        ]);

        $result = $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->some(
                3,
                ...array_map(static fn(int $n): Awaitable => $wf->activityStub(SuiteActivities::class)->price($n), range(1, 8)),
            )),
            'quorum-1',
        );

        self::assertIsArray($result);
        self::assertCount(3, $result);
        // Indexed by declaration position: the caller knows which ones answered.
        self::assertSame([0, 1, 2], array_keys($result));
    }

    public function testAFailingMemberDoesNotCountTowardsTheQuorum(): void
    {
        // The quorum exists to survive members that fall over: counting the failures would make
        // it strictly worse than an all().
        $env = WorkflowTestEnvironment::inMemory([
            'boom' => static fn(): never => throw new \RuntimeException('provider down'),
            'ok' => static fn(array $p): string => 'quote-' . ($p['n'] ?? '?'),
        ]);

        $result = $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->some(
                2,
                $wf->activityStub(SuiteActivities::class, self::onceOnly())->boom(),
                $wf->activityStub(SuiteActivities::class)->ok(1),
                $wf->activityStub(SuiteActivities::class, self::onceOnly())->boom(),
                $wf->activityStub(SuiteActivities::class)->ok(2),
            )),
            'quorum-2',
        );

        self::assertSame([1 => 'quote-1', 3 => 'quote-2'], $result);
    }

    public function testAnUnreachableQuorumFailsRatherThanHangs(): void
    {
        // Three breakdowns out of four: the quorum of two can no longer fall. Raising nothing
        // would be an execution suspended forever.
        $env = WorkflowTestEnvironment::inMemory([
            'boom' => static fn(): never => throw new \RuntimeException('provider down'),
            'ok' => static fn(): string => 'quote',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/provider down/');

        $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->some(
                2,
                $wf->activityStub(SuiteActivities::class, self::onceOnly())->boom(),
                $wf->activityStub(SuiteActivities::class, self::onceOnly())->boom(),
                $wf->activityStub(SuiteActivities::class, self::onceOnly())->boom(),
                $wf->activityStub(SuiteActivities::class)->ok(),
            )),
            'quorum-3',
        );
    }

    public function testAQuorumThatCanNeverBeReachedIsRefusedAtTheCallSite(): void
    {
        $env = WorkflowTestEnvironment::inMemory(['a' => static fn(): string => 'a']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/never settle/');

        $env->run(
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->some(3, $wf->activityStub(SuiteActivities::class)->a())),
            'quorum-4',
        );
    }

    public function testARaceWithNoRunnerIsRefused(): void
    {
        $env = WorkflowTestEnvironment::inMemory([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one awaitable/');

        $env->run(static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->any()), 'quorum-5');
    }

    /**
     * @return list<string>
     */
    private function cancelledTimers(InMemoryEventStore $store, string $executionId): array
    {
        $out = [];
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof \Gplanchat\Durable\Event\TimerCancelled) {
                $out[] = $event->timerId();
            }
        }

        return $out;
    }

    private static function onceOnly(): \Gplanchat\Durable\Activity\ActivityOptions
    {
        return \Gplanchat\Durable\Activity\ActivityOptions::of(\Gplanchat\Durable\Activity\RetryLimit::once());
    }
}
