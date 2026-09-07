<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Replaying a Nexus operation on the journal backend.
 *
 * This backend's buffer **refuses** to schedule a Nexus operation (§3.4): none can therefore
 * appear in its journal, and the history source answers "nothing" whatever the slot. This is not
 * an implementation gap but the exact consequence of the refusal, and that is what this test pins
 * down — so that a future "just in case" implementation gets noticed.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §3.3
 */
final class NexusHistorySourceTest extends TestCase
{
    public function testAJournalBackedExecutionHasNoNexusOperationResult(): void
    {
        self::assertNull($this->source()->findNexusOperationSlotResult(0));
    }

    public function testAJournalBackedExecutionHasNoScheduledNexusOperation(): void
    {
        self::assertNull($this->source()->findScheduledNexusOperation(0));
    }

    public function testTheAnswerIsTheSameForAnySlot(): void
    {
        // No slot bound: the answer does not depend on the rank, it depends on the backend.
        $source = $this->source();

        foreach ([0, 1, 7, 999] as $slot) {
            self::assertNull($source->findNexusOperationSlotResult($slot));
            self::assertNull($source->findScheduledNexusOperation($slot));
        }
    }

    private function source(): EventStoreHistorySource
    {
        return new EventStoreHistorySource(new InMemoryEventStore(), 'exec-1');
    }
}
