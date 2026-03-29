<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Query\WorkflowQueryRunner;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(WorkflowQueryRunner::class)]
final class WorkflowQueryRunnerTest extends TestCase
{
    #[Test]
    public function delegatesToJournalLikeTemporalStyleQueries(): void
    {
        $store = new InMemoryEventStore();
        $id = 'wf-query-1';
        $store->append(new ExecutionStarted($id));
        $store->append(new WorkflowSignalReceived($id, 'orderPaid', ['amount' => 100]));
        $store->append(new WorkflowUpdateHandled($id, 'applyDiscount', ['pct' => 10], ['newTotal' => 90]));
        $store->append(new ExecutionCompleted($id, ['status' => 'shipped']));

        $runner = new WorkflowQueryRunner($store);

        self::assertSame(['status' => 'shipped'], $runner->lastExecutionResult($id));
        self::assertSame(
            [['name' => 'orderPaid', 'payload' => ['amount' => 100]]],
            $runner->signalsReceived($id),
        );
        self::assertSame(
            [['name' => 'applyDiscount', 'arguments' => ['pct' => 10], 'result' => ['newTotal' => 90]]],
            $runner->updatesHandled($id),
        );
    }
}
