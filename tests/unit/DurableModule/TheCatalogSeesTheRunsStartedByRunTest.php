<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use PHPUnit\Framework\TestCase;
use unit\DurableModule\Fixture\OrderWorkflow;
use unit\DurableModule\Fixture\RecordingOrderActivities;

/**
 * Without a DSN, a run started by `MagentoRuntime::run()` is found by a later `catalog()` call
 * from the same factory (#985).
 */
final class TheCatalogSeesTheRunsStartedByRunTest extends TestCase
{
    public function testARunStartedByRunIsFoundThroughTheCatalog(): void
    {
        $factory = new RuntimeFactory(
            workflowClasses: [OrderWorkflow::class],
            activityHandlers: [new RecordingOrderActivities()],
        );
        $factory->create()->run(OrderWorkflow::class, ['orderId' => 'ORD-1'], 'order-1');

        $run = $factory->catalog()->findRun(ExecutionId::fromString('order-1'));

        self::assertNotNull($run);
        self::assertSame(WorkflowRunStatus::Completed, $run->status);
    }
}
