<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Unit\Durable\Bundle;

use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class DurableExecutionTraceTest extends TestCase
{
    /**
     * @test
     */
    public function recordsDispatchWorkflowAndActivityInOrder(): void
    {
        $t = new DurableExecutionTrace();
        $t->onWorkflowDispatchRequested('e1', 'MyWorkflow', ['x' => 1], false, 'sync');
        $t->onWorkflowRun('e1', 'MyWorkflow', false);
        $t->onActivityExecuted('e1', 'a1', 'DoThing', 0.01, true, null);

        $line = $t->getTimeline();
        self::assertCount(3, $line);
        self::assertSame('dispatch', $line[0]['kind']);
        self::assertSame('workflow', $line[1]['kind']);
        self::assertSame('activity', $line[2]['kind']);
        self::assertSame(1, $t->countDispatchEvents());
    }

    /**
     * @test
     */
    public function collectsExecutionIdFromActivityOnlyPath(): void
    {
        $t = new DurableExecutionTrace();
        $t->onActivityExecuted('e-worker', 'a1', 'Task', 0.05, true, null);

        $line = $t->getTimeline();
        self::assertCount(1, $line);
        self::assertSame('e-worker', $line[0]['executionId']);
    }
}
