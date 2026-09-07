<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Replay;

use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use PHPUnit\Framework\TestCase;

/**
 * The same guard, on child workflow slots.
 *
 * A child is identified by its **type**. Its execution id, on the other hand, is generated:
 * comparing it would make a perfectly faithful replay diverge.
 *
 * Nexus operations have their own test, on the bridge side: the journal backend refuses them by
 * construction (DUR036), and testing the guard there would be testing an impossible situation.
 *
 * @see \unit\Gplanchat\Bridge\Temporal\Worker\NexusSlotDivergenceTest
 */
final class ChildWorkflowSlotDivergenceTest extends TestCase
{
    private const EXECUTION = 'exec-2425';

    public function testAChildOfAnotherTypeIsRefused(): void
    {
        $context = $this->contextWithChild('ChargeCardWorkflow');

        $this->expectException(WorkflowTaskFailure::class);
        $context->executeChildWorkflow('ReserveStockWorkflow', ['sku' => 'ABC']);
    }

    public function testTheChildRefusalNamesBothTypes(): void
    {
        $context = $this->contextWithChild('ChargeCardWorkflow');

        try {
            $context->executeChildWorkflow('ReserveStockWorkflow', ['sku' => 'ABC']);
            self::fail('The divergence should have been refused.');
        } catch (WorkflowTaskFailure $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('ChargeCardWorkflow', $message);
        self::assertStringContainsString('ReserveStockWorkflow', $message);
    }

    public function testTheMessageNamesTheSlotKind(): void
    {
        // "activity" and "child workflow" are not looked up in the same place in a history:
        // confusing the two costs the reader the time they have just saved.
        $context = $this->contextWithChild('ChargeCardWorkflow');

        try {
            $context->executeChildWorkflow('ReserveStockWorkflow', ['sku' => 'ABC']);
            self::fail('The divergence should have been refused.');
        } catch (WorkflowTaskFailure $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('child workflow slot 0', $message);
        self::assertStringNotContainsString('activity slot', $message);
    }

    public function testAnUnchangedChildTypeStillReplays(): void
    {
        $context = $this->contextWithChild('ChargeCardWorkflow');

        $awaitable = $context->executeChildWorkflow('ChargeCardWorkflow', ['sku' => 'ABC']);

        self::assertNotNull($awaitable, 'The unchanged type must not diverge: the generated execution id does not enter the comparison.');
    }

    private function contextWithChild(string $childType): ExecutionContext
    {
        $store = new InMemoryEventStore();
        $store->append(new ChildWorkflowScheduled(self::EXECUTION, 'child-1', $childType, ['sku' => 'ABC']));

        return $this->context($store);
    }

    private function context(InMemoryEventStore $store): ExecutionContext
    {
        return new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
            $this->createStub(ChildWorkflowRunnerInterface::class),
        );
    }
}
