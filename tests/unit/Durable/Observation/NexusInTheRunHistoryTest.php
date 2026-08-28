<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Bridge\Temporal\Store\TemporalRunHistoryReader;
use Gplanchat\Durable\Event\NexusOperationCompleted;
use Gplanchat\Durable\Event\NexusOperationScheduled;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Une opération Nexus est le seul endroit d'une exécution où l'attente est **servie par quelqu'un
 * d'autre**. Un exploitant qui voit un workflow bloqué sans voir cette opération cherchera la panne
 * dans son propre système, là où elle est à l'extérieur.
 *
 * Elles tombaient jusqu'ici sur la voie `Other`, listées mais sans identité : ni endpoint, ni
 * service, ni nom d'opération — c'est-à-dire sans ce qui dit **chez qui** l'attente a lieu.
 */
final class NexusInTheRunHistoryTest extends TestCase
{
    public function testAScheduledOperationGetsItsOwnLane(): void
    {
        $history = $this->read([
            new NexusOperationScheduled('exec-1', 5, 'paiements', 'facturation', 'encaisser', [], []),
        ]);

        self::assertSame(WorkflowRunEventKind::Nexus, $history[0]->kind);
    }

    public function testTheLabelSaysWhereTheWaitHappens(): void
    {
        $history = $this->read([
            new NexusOperationScheduled('exec-1', 5, 'paiements', 'facturation', 'encaisser', [], []),
        ]);

        self::assertStringContainsString('paiements', $history[0]->label);
        self::assertStringContainsString('facturation', $history[0]->label);
        self::assertStringContainsString('encaisser', $history[0]->label);
    }

    public function testATerminalEventBorrowsTheIdentityOfItsScheduling(): void
    {
        // Les événements terminaux ne portent que le `scheduledEventId` — la même contrainte que
        // pour les activités, où le nom se lit sur la planification. Sans cette corrélation, la
        // frise afficherait « NexusOperationCompleted » et l'exploitant ne saurait pas laquelle.
        $history = $this->read([
            new NexusOperationScheduled('exec-1', 5, 'paiements', 'facturation', 'encaisser', [], []),
            new NexusOperationCompleted('exec-1', 5, ['receipt' => 'r-1']),
        ]);

        self::assertCount(2, $history);
        self::assertSame(WorkflowRunEventKind::Nexus, $history[1]->kind);
        self::assertStringContainsString('encaisser', $history[1]->label);
    }

    public function testTheTemporalReaderUsesTheSameLane(): void
    {
        // Les deux backends alimentent la même frise. Si l'un range Nexus sur sa voie et l'autre
        // sur `Other`, le même workflow se lit différemment selon l'endroit où il tourne — et
        // l'exploitant apprend à ne pas faire confiance à la voie.
        $kind = (new \ReflectionMethod(TemporalRunHistoryReader::class, 'kindOf'));
        $kind->setAccessible(true);

        foreach ([
            'EVENT_TYPE_NEXUS_OPERATION_SCHEDULED',
            'EVENT_TYPE_NEXUS_OPERATION_STARTED',
            'EVENT_TYPE_NEXUS_OPERATION_COMPLETED',
            'EVENT_TYPE_NEXUS_OPERATION_FAILED',
            'EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT',
            'EVENT_TYPE_NEXUS_OPERATION_CANCELED',
            'EVENT_TYPE_NEXUS_OPERATION_CANCEL_REQUESTED',
        ] as $eventType) {
            self::assertSame(
                WorkflowRunEventKind::Nexus,
                $kind->invoke(null, $eventType),
                $eventType . ' doit tomber sur la voie Nexus',
            );
        }
    }

    /**
     * @param list<object> $events
     *
     * @return list<\Gplanchat\Durable\Observation\WorkflowRunEvent>
     */
    private function read(array $events): array
    {
        $store = new InMemoryEventStore();
        foreach ($events as $event) {
            $store->append($event);
        }

        return (new JournalRunHistoryReader($store))->read('exec-1');
    }
}
