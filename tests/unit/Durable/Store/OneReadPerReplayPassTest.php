<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\SuiteActivities;

/**
 * A replay pass used to read the whole stream once per history query: several per activity,
 * five per version(), so replay cost grew with the square of the journal (#320).
 */
final class OneReadPerReplayPassTest extends TestCase
{
    private const N = 5;

    public function testAReplayPassReadsTheStreamOnce(): void
    {
        $store = new InMemoryEventStore();
        $executor = new RegistryActivityExecutor();
        $executor->register('double', static fn(array $p): int => ($p['value'] ?? 0) * 2);

        $handler = static function (WorkflowEnvironment $env): int {
            $sum = $env->version('bonus', -1, 1);
            for ($i = 0; $i < self::N; ++$i) {
                $sum += $env->await($env->activityStub(SuiteActivities::class)->double($i));
                $env->await($env->timer(1.0));
                $sum += $env->sideEffect(static fn(): int => 1);
            }

            return $sum;
        };

        $expected = (new InMemoryWorkflowRunner($store, new InMemoryActivityTransport(), $executor))->run('exec-1', $handler);

        $counting = new class ($store) implements EventStoreInterface {
            public int $historyReads = 0;

            public function __construct(private readonly EventStoreInterface $inner) {}

            public function append(Event $event): void
            {
                $this->inner->append($event);
            }

            public function readStream(string $executionId): iterable
            {
                // Only the history source's reads: the lifecycle's own checks are not replay cost.
                foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                    if (str_ends_with($frame['class'] ?? '', 'EventStoreHistorySource')) {
                        ++$this->historyReads;
                        break;
                    }
                }

                return $this->inner->readStream($executionId);
            }

            public function readStreamWithRecordedAt(string $executionId): iterable
            {
                return $this->inner->readStreamWithRecordedAt($executionId);
            }

            public function countEventsInStream(string $executionId): int
            {
                return $this->inner->countEventsInStream($executionId);
            }
        };

        $runtime = new ExecutionRuntime($counting, new InMemoryActivityTransport(), $executor, 0, null, true);
        $replayed = (new ExecutionEngine($counting, $runtime))->resume('exec-1', $handler);

        self::assertSame($expected, $replayed, 'the replay reads the journal back to the same result');
        self::assertSame(1, $counting->historyReads, 'one read of the stream for the whole pass');
    }
}
