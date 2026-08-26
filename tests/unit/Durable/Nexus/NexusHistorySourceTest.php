<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * La relecture d'une opération Nexus sur le backend journal.
 *
 * Le tampon de ce backend **refuse** de planifier une opération Nexus (§3.4) : aucune ne peut donc
 * figurer dans son journal, et la source d'historique répond « rien » quel que soit le slot. Ce
 * n'est pas un trou d'implémentation mais la conséquence exacte du refus, et c'est ce que ce test
 * épingle — pour qu'une future implémentation « au cas où » se remarque.
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
        // Aucune borne de slot : la réponse ne dépend pas du rang, elle dépend du backend.
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
