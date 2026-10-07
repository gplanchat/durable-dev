<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Testing;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * An inline child used to start its virtual time at the real now: after its parent had skipped
 * an hour to a timer, the child's instants lagged an hour behind the parent's (#652).
 */
final class AChildStartsAtItsParentsVirtualTimeTest extends TestCase
{
    public function testTheChildsTimerIsScheduledOnTheParentsVirtualTime(): void
    {
        $env = WorkflowTestEnvironment::inMemory();
        $env->registerWorkflowClass(ChildThatSleeps::class);

        $env->run(static function (WorkflowEnvironment $wf): mixed {
            $wf->await($wf->timer(3600));

            return $wf->await($wf->childWorkflowStub(ChildThatSleeps::class)->run());
        }, 'parent-clock');

        $parentTimer = self::firstTimer($env->getEventStore(), 'parent-clock');
        $childTimer = self::firstTimer($env->getEventStore(), self::childOf($env->getEventStore(), 'parent-clock'));

        // The skip moves in whole milliseconds: a second of slack, against an hour of lag.
        self::assertEqualsWithDelta(
            $parentTimer->scheduledAt() + 60.0,
            $childTimer->scheduledAt(),
            1.0,
            'the child starts at the parent\'s virtual now, past the parent\'s hour-long timer',
        );
    }

    public function testTheChildsDelayedRetryStillFallsDueOnTheTransportsClock(): void
    {
        $attempts = 0;
        $env = WorkflowTestEnvironment::inMemory(['childFlaky' => static function () use (&$attempts): string {
            if (++$attempts < 2) {
                throw new \RuntimeException('boom');
            }

            return 'done';
        }]);
        $env->registerWorkflowClass(ChildThatRetries::class);

        $startedAt = hrtime(true);
        $result = $env->run(static function (WorkflowEnvironment $wf): mixed {
            $wf->await($wf->timer(3600));

            return $wf->await($wf->childWorkflowStub(ChildThatRetries::class)->run());
        });

        // The retry is stamped on, and dequeued against, the transport's (real) clock. Had the
        // child's queue run on the virtual clock an hour ahead, the drain would see the retry as
        // due while dequeue() handed nothing out, and the run would end as budget exhausted.
        self::assertSame('done', $result);
        self::assertSame(2, $attempts);
        // The backoff itself is still waited out.
        self::assertGreaterThanOrEqual(0.2, ((float) (hrtime(true) - $startedAt)) / 1e9);
    }

    private static function firstTimer(EventStoreInterface $store, string $executionId): TimerScheduled
    {
        foreach ($store->readStream(ExecutionId::fromString($executionId)) as $event) {
            if ($event instanceof TimerScheduled) {
                return $event;
            }
        }
        self::fail(\sprintf('No timer was scheduled on %s.', $executionId));
    }

    private static function childOf(EventStoreInterface $store, string $executionId): string
    {
        foreach ($store->readStream(ExecutionId::fromString($executionId)) as $event) {
            if ($event instanceof ChildWorkflowScheduled) {
                return $event->childExecutionId()->toString();
            }
        }
        self::fail('No child was scheduled.');
    }
}

#[AsWorkflow(name: 'ChildThatSleeps')]
final class ChildThatSleeps
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(): string
    {
        $this->environment->await($this->environment->timer(60));

        return 'slept';
    }
}

#[AsWorkflow(name: 'ChildThatRetries')]
final class ChildThatRetries
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(): string
    {
        return $this->environment->await($this->environment->activityStub(ChildFlakyActivities::class, new ActivityOptions(
            retryLimit: RetryLimit::ofAttempts(3),
            initialInterval: Duration::seconds(0.2),
            backoffCoefficient: 1.0,
        ))->childFlaky());
    }
}

interface ChildFlakyActivities
{
    #[AsActivityMethod('childFlaky')]
    public function childFlaky(): string;
}
