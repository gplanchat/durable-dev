<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Store\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * Une activité planifiée, démarrée puis terminée est **une action et trois événements**.
 *
 * Une frise rangée par nature — « les activités », « les signaux » — obligeait l'exploitant à
 * recoller trois repères de l'œil pour savoir combien de temps celle-là avait duré. Le lien
 * existait pourtant déjà dans le journal ; c'est la traduction qui le jetait.
 */
final class TheTimelineGroupsByActionTest extends TestCase
{
    public function testTheThreeEventsOfAnActivityAreOneAction(): void
    {
        $history = $this->read([
            new ActivityScheduled('exec-1', 'act-1', 'charge', [], []),
            new ActivityTaskStarted('exec-1', 'act-1', 'charge', 1),
            new ActivityCompleted('exec-1', 'act-1', ['receipt' => 'r-1']),
        ]);

        self::assertCount(1, array_unique(array_column($history, 'actionKey')));
        self::assertSame('activity:act-1', $history[0]->actionKey);
    }

    public function testTwoActivitiesAreTwoActions(): void
    {
        // Le regroupement doit distinguer, sinon la frise met sur une seule ligne deux attentes
        // qui n'ont rien à voir et fait passer leur somme pour une durée.
        $history = $this->read([
            new ActivityScheduled('exec-1', 'act-1', 'charge', [], []),
            new ActivityScheduled('exec-1', 'act-2', 'notify', [], []),
        ]);

        self::assertNotSame($history[0]->actionKey, $history[1]->actionKey);
    }

    public function testATimerIsAnActionToo(): void
    {
        $history = $this->read([
            new TimerScheduled('exec-1', 'tim-1', 1700000000.0, 'avant relance'),
            new TimerCompleted('exec-1', 'tim-1'),
        ]);

        self::assertSame('timer:tim-1', $history[0]->actionKey);
        self::assertSame($history[0]->actionKey, $history[1]->actionKey);
    }

    public function testATimerIsNamedByItsSummaryAndNotByItsClass(): void
    {
        // « TimerScheduled » nomme la classe, pas l'attente. Une ligne de frise porte le nom de son
        // action, et c'est celui-là qu'un exploitant est venu lire.
        $history = $this->read([
            new TimerScheduled('exec-1', 'tim-1', 1700000000.0, 'avant relance'),
            new TimerCompleted('exec-1', 'tim-1'),
        ]);

        self::assertSame('avant relance', $history[0]->label);
        self::assertSame('avant relance', $history[1]->label, 'la suite emprunte le nom de sa planification');
    }

    public function testAnEventThatIsItsOwnActionSaysSoWithNull(): void
    {
        // `null` est une réponse — « cet événement est à lui seul son action » — et c'est ce qui
        // permet à la frise de lui donner sa ligne sans inventer une clé.
        $history = $this->read([
            new WorkflowSignalReceived('exec-1', 'orderApproved', []),
        ]);

        self::assertNull($history[0]->actionKey);
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
