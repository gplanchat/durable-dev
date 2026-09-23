<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * A timer given as an instant — `sleep(new \DateTimeImmutable('+1 hour'))`, or a deadline on
 * `await()` — is a length of time only once related to *now*. The resume that follows the timer's
 * own firing is, by construction, after the instant: the length is then negative, and a negative
 * duration is refused. Every such timer used to fail its execution on the pass that came to
 * collect it (#314).
 *
 * The slot decides, not the clock: on replay the recorded outcome is served whatever the instant
 * has become, and on a first pass an instant already behind us is a wait that is over.
 */
final class TimerFromAnInstantTest extends TestCase
{
    public function testASleepUntilAnInstantResumesAfterTheInstantHasPassed(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $due = new \DateTimeImmutable('+40 milliseconds');
        $handler = static function (WorkflowEnvironment $wf) use ($due): string {
            $wf->sleep($due);

            return 'woke up';
        };

        try {
            $engine->start('instant-1', $handler);
            self::fail('the workflow was to suspend on its timer');
        } catch (WorkflowSuspendedException) {
        }

        // The timer fires — necessarily once the instant is behind us.
        usleep(60_000);
        $store->append(new TimerCompleted('instant-1', $this->firstTimerId($store, 'instant-1')));

        self::assertSame('woke up', $engine->resume('instant-1', $handler));
    }

    public function testADeadlineGivenAsAnInstantIsReplayedAfterItFired(): void
    {
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $due = new \DateTimeImmutable('+40 milliseconds');
        $handler = static function (WorkflowEnvironment $wf) use ($due): string {
            try {
                $wf->await(static fn(): bool => false, $due);

                return 'settled';
            } catch (\Gplanchat\Durable\Exception\DeadlineExceededException) {
                return 'expired';
            }
        };

        try {
            $engine->start('instant-2', $handler);
            self::fail('the workflow was to suspend on its deadline');
        } catch (WorkflowSuspendedException) {
        }

        usleep(60_000);
        $store->append(new TimerCompleted('instant-2', $this->firstTimerId($store, 'instant-2')));

        self::assertSame('expired', $engine->resume('instant-2', $handler));
    }

    public function testAnInstantAlreadyBehindUsIsAWaitThatIsOver(): void
    {
        // First pass, not a replay: the instant is in the past before the timer even exists. It
        // is scheduled with no delay rather than refused — the caller asked to wait until then,
        // and "then" has come.
        $store = new InMemoryEventStore();
        $engine = $this->engine($store);
        $handler = static function (WorkflowEnvironment $wf): string {
            $wf->sleep(new \DateTimeImmutable('-1 hour'));

            return 'woke up';
        };

        try {
            $engine->start('instant-3', $handler);
            self::fail('the workflow was to suspend on its timer');
        } catch (WorkflowSuspendedException) {
        }

        $scheduled = $this->eventsOf($store, 'instant-3', TimerScheduled::class);
        self::assertCount(1, $scheduled);
    }

    private function engine(InMemoryEventStore $store): ExecutionEngine
    {
        return new ExecutionEngine(
            $store,
            new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
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
