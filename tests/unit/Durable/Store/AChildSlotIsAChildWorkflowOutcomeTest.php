<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\History\ChildWorkflowOutcome;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * #325: the child slot returned an array{childExecutionId, result, failed}; it is a readonly value
 * object, like the activity, Nexus and timer slots.
 */
final class AChildSlotIsAChildWorkflowOutcomeTest extends TestCase
{
    public function testACompletedChildIsItsIdAndItsResult(): void
    {
        $store = new InMemoryEventStore();
        $store->append(new ChildWorkflowScheduled(ExecutionId::fromString('parent-1'), ExecutionId::fromString('child-1'), 'App\\Child', []));
        $store->append(new ChildWorkflowCompleted(ExecutionId::fromString('parent-1'), ExecutionId::fromString('child-1'), ['ok' => true]));

        $slot = (new EventStoreHistorySource($store, ExecutionId::fromString('parent-1')))->findChildWorkflowForSlot(0);

        self::assertInstanceOf(ChildWorkflowOutcome::class, $slot);
        self::assertSame('child-1', $slot->childExecutionId);
        self::assertSame(['ok' => true], $slot->result);
        self::assertNull($slot->failed);
    }
}
