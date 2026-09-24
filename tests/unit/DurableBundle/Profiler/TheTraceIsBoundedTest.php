<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\Profiler;

use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use PHPUnit\Framework\TestCase;

/**
 * `kernel.reset` never fires on a Temporal worker: its transports work inside `get()` and return
 * nothing, so the worker always looks idle. The trace bounds itself, one guard for HTTP,
 * Messenger and Temporal alike (#336).
 */
final class TheTraceIsBoundedTest extends TestCase
{
    public function testFiveThousandEntriesLeaveTheLastTwoThousand(): void
    {
        $trace = new DurableExecutionTrace();
        for ($i = 1; $i <= 5000; ++$i) {
            $trace->onWorkflowRun("exec-{$i}", 'Order', false);
        }

        $timeline = $trace->getTimeline();

        self::assertCount(DurableExecutionTrace::MAX_ENTRIES, $timeline);
        self::assertSame(2000, DurableExecutionTrace::MAX_ENTRIES);
        self::assertSame('exec-3001', $timeline[0]['executionId']);
        self::assertSame('exec-5000', $timeline[1999]['executionId']);
        self::assertSame(5000, $timeline[1999]['seq'], 'the sequence keeps counting: a gap says entries were dropped');
    }
}
