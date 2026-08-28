<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
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

    public function testTheRunsOwnEventsAreTheFirstAction(): void
    {
        // Une tâche de workflow n'est pas un fait métier, c'est le mécanisme par lequel le moteur
        // avance. Une ligne par occurrence noyait les actions intéressantes sous la plomberie.
        $history = $this->read([
            new ExecutionStarted('exec-1', []),
            new ActivityScheduled('exec-1', 'act-1', 'charge', [], []),
            new ActivityCompleted('exec-1', 'act-1', null),
            new ExecutionCompleted('exec-1', null),
        ], 'App\\OrderWorkflow');

        self::assertSame('workflow', $history[0]->actionKey);
        self::assertSame('workflow', $history[3]->actionKey, 'la fin de l\'exécution rejoint son début');
        self::assertSame('activity:act-1', $history[1]->actionKey);
    }

    public function testASignalIsNotPartOfTheRunsOwnAction(): void
    {
        // Le piège est là : un signal reçu porte le même vocabulaire que l'exécution. Rangé avec
        // elle, il disparaît dans la première ligne au lieu d'être l'attente qu'il est.
        $history = $this->read([
            new ExecutionStarted('exec-1', []),
            new WorkflowSignalReceived('exec-1', 'orderApproved', []),
        ], 'App\\OrderWorkflow');

        self::assertSame('workflow', $history[0]->actionKey);
        self::assertNull($history[1]->actionKey);
    }

    public function testTheRunsLineIsNamedByTheWorkflowAndNotByAnEventClass(): void
    {
        // Le journal ne connaît qu'un flux : le nom vient de l'appelant, qui tient la description
        // de l'exécution. Sans lui, la première ligne s'appellerait « ExecutionStarted ».
        $history = $this->read([new ExecutionStarted('exec-1', [])], 'App\\OrderWorkflow');

        self::assertSame('App\\OrderWorkflow', $history[0]->label);
    }

    public function testAChildWorkflowKeepsItsOwnLineAndItsOwnName(): void
    {
        $history = $this->read([
            new ExecutionStarted('exec-1', []),
            new ChildWorkflowScheduled('exec-1', 'child-1', 'App\\ShipmentWorkflow', []),
            new ChildWorkflowCompleted('exec-1', 'child-1', null),
        ], 'App\\OrderWorkflow');

        self::assertSame('child:child-1', $history[1]->actionKey);
        self::assertSame($history[1]->actionKey, $history[2]->actionKey);
        self::assertNotSame($history[0]->actionKey, $history[1]->actionKey, "l'enfant n'est pas le parent");
        self::assertSame('App\\ShipmentWorkflow', $history[1]->label);
        self::assertSame('App\\ShipmentWorkflow', $history[2]->label, 'la suite emprunte le nom de sa planification');
    }

    /**
     * @param list<\Gplanchat\Durable\Event\Event> $events
     *
     * @return list<WorkflowRunEvent>
     */
    private function read(array $events, string $workflowName = ''): array
    {
        $store = new InMemoryEventStore();
        foreach ($events as $event) {
            $store->append($event);
        }

        return (new JournalRunHistoryReader($store))->read('exec-1', $workflowName);
    }
}
