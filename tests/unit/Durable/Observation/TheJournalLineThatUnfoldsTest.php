<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Une frise dit « quoi ». Un exploitant demande « avec quoi » dans la seconde qui suit.
 *
 * `WorkflowRunEvent` ne portait que la séquence, l'horodatage, la voie et un libellé : de quoi
 * ranger les lignes, pas de quoi répondre à la deuxième question. Un dépliant construit là-dessus
 * se serait ouvert sur du vide.
 */
final class TheJournalLineThatUnfoldsTest extends TestCase
{
    public function testAnEventCarriesWhatItWasCalledWith(): void
    {
        $history = $this->read([
            new ActivityScheduled('exec-1', 'act-1', 'charge', ['orderId' => 'ORD-4242'], []),
        ]);

        self::assertSame(WorkflowRunEventKind::Activity, $history[0]->kind);
        self::assertNotSame([], $history[0]->details, 'une activité planifiée a de quoi être dépliée');
        self::assertStringContainsString(
            'ORD-4242',
            json_encode($history[0]->details, \JSON_THROW_ON_ERROR),
            "l'entrée de l'activité doit se lire dans le détail",
        );
    }

    public function testTheResultIsThereToo(): void
    {
        $history = $this->read([
            new ActivityScheduled('exec-1', 'act-1', 'charge', ['orderId' => 'ORD-4242'], []),
            new ActivityCompleted('exec-1', 'act-1', ['receipt' => 'rcpt-7']),
        ]);

        self::assertStringContainsString(
            'rcpt-7',
            json_encode($history[1]->details, \JSON_THROW_ON_ERROR),
            'un exploitant vient lire ce que la charge a répondu, pas seulement qu\'elle a répondu',
        );
    }

    public function testTheFieldIsAdditive(): void
    {
        // Le champ arrive en fin de constructeur avec une valeur par défaut : tout appelant écrit
        // avant lui — le pont Temporal, le plugin Sylius, les tests — continue de construire.
        $event = new WorkflowRunEvent(1, new \DateTimeImmutable('@0'), WorkflowRunEventKind::Other, 'x');

        self::assertSame([], $event->details);
    }

    /**
     * @param list<\Gplanchat\Durable\Event\Event> $events
     *
     * @return list<WorkflowRunEvent>
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
