<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\RunTimeline;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use PHPUnit\Framework\TestCase;

/**
 * La frise se calcule **une fois**, à côté du modèle d'observation, et elle se mesure en secondes.
 *
 * Deux surfaces la dérivaient chacune de son côté : Magento plaçait les actions dans le temps,
 * Sylius les empilait. Le même run se lisait donc différemment selon l'application ouverte, alors
 * que c'est le même journal dessous.
 *
 * ⚠ Et elle rend des **secondes**, jamais des pourcentages. Le bloc Magento rendait des flottants
 * de 0 à 100, c'est-à-dire une largeur CSS : le cœur se serait mis à dessiner pour une surface API
 * Platform qui ne rend aucun balisage. Mettre à l'échelle est le métier de l'hôte ; mesurer est le
 * nôtre.
 */
final class TheRunTimelinePositionsActionsInTimeTest extends TestCase
{
    public function testTheThreeEventsOfAnActivityAreOneLine(): void
    {
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:02.000', 'charge', actionKey: 'activity:act-1', started: true),
            $this->event(3, '12:00:05.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertCount(1, $timeline->actions);
        self::assertSame('charge', $timeline->actions[0]->label);
        self::assertCount(3, $timeline->actions[0]->events);
    }

    public function testAnActionIsPlacedAndMeasuredInSeconds(): void
    {
        // Le fait à ne pas perdre : 22 secondes valent 22.0, pas « 73.3 % de la barre ». L'hôte
        // met à l'échelle avec ce qu'il sait de sa colonne ; le cœur n'en sait rien.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'start'),
            $this->event(2, '12:00:08.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(3, '12:00:30.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertSame(30.0, $timeline->span);
        $charge = $timeline->actions[1];
        self::assertSame(8.0, $charge->offset);
        self::assertSame(22.0, $charge->duration);
    }

    public function testADurationIsWordedTheSameWayOnEverySurface(): void
    {
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:00.240', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertSame('240 ms', $timeline->actions[0]->durationLabel);
        self::assertSame('240 ms', $timeline->spanLabel);
    }

    public function testWaitingToBePickedUpIsNotShownAsWork(): void
    {
        // Le segment hérite du `started` de l'événement qui le **ferme** : ce qui précède la prise
        // en charge est le temps passé à attendre qu'on veuille bien commencer.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:20.000', 'charge', actionKey: 'activity:act-1', started: true),
            $this->event(3, '12:00:21.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        $segments = $timeline->actions[0]->segments;
        self::assertCount(2, $segments);
        self::assertTrue($segments[0]->waiting, 'les vingt secondes avant la prise en charge sont une file');
        self::assertSame(20.0, $segments[0]->duration);
        self::assertFalse($segments[1]->waiting, 'la seconde qui suit est du travail');
    }

    public function testAFailureIsCarriedByTheIntervalThatEndsOnIt(): void
    {
        // Peindre l'action entière ferait passer une activité reprise du deuxième coup pour une
        // activité perdue.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:01.000', 'charge', actionKey: 'activity:act-1', failed: true),
            $this->event(3, '12:00:02.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        $segments = $timeline->actions[0]->segments;
        self::assertTrue($segments[0]->failed);
        self::assertFalse($segments[1]->failed);
    }

    public function testASegmentNamesTheTwoEventsItSpans(): void
    {
        // L'hôte compose son infobulle avec les deux bouts, sans recompter les index : un couplage
        // par rang est exactement ce qu'on relit à trois heures du matin.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:01.000', 'charge', actionKey: 'activity:act-1', started: true),
        ]);

        $segment = $timeline->actions[0]->segments[0];
        self::assertSame(1, $segment->from->sequence);
        self::assertSame(2, $segment->to->sequence);
    }

    public function testAnEventThatIsItsOwnActionHasNoInterval(): void
    {
        // Un repère seul dit déjà tout ce qu'il y a à dire d'un instant : lui inventer un segment
        // lui donnerait une durée qu'il n'a pas.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'orderApproved', kind: WorkflowRunEventKind::Signal),
        ]);

        self::assertCount(1, $timeline->actions);
        self::assertSame([], $timeline->actions[0]->segments);
        self::assertSame(0.0, $timeline->actions[0]->duration);
    }

    public function testTwoEventsInTheSameMicrosecondAreNotSpreadByRank(): void
    {
        // Étaler par rang ferait passer un ordre pour une durée, et un run d'une milliseconde
        // ressemblerait à un run d'une heure.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'start'),
            $this->event(2, '12:00:00.000', 'orderApproved', kind: WorkflowRunEventKind::Signal),
        ]);

        self::assertSame(0.0, $timeline->span);
        self::assertSame(0.0, $timeline->actions[0]->offset);
        self::assertSame(0.0, $timeline->actions[1]->offset);
    }

    public function testARunStillGoingEndsOnItsLastRecordedFact(): void
    {
        // L'échelle va du premier au dernier événement enregistré, pas du début à la fin de
        // l'exécution : une exécution en cours n'a pas de fin, et la frise ne prétend rien savoir
        // de plus que ce qui est écrit.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'start'),
            $this->event(2, '12:00:04.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertSame(4.0, $timeline->span);
    }

    public function testAnEmptyHistoryIsAnEmptyTimelineRatherThanNothing(): void
    {
        // Une exécution purgée, ou jamais vue, n'est pas une erreur d'appel : l'hôte doit pouvoir
        // l'afficher sans rien avoir à rattraper.
        $timeline = RunTimeline::of([]);

        self::assertSame([], $timeline->actions);
        self::assertSame(0.0, $timeline->span);
    }

    public function testEachEventKeepsItsOwnPositionInsideItsAction(): void
    {
        // Le repère d'un événement se place dans le temps du run, pas dans celui de son action :
        // c'est ce qui permet de le retrouver sur la même verticale que ceux des autres lignes.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'start'),
            $this->event(2, '12:00:03.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(3, '12:00:07.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        $marks = $timeline->actions[1]->events;
        self::assertSame(3.0, $marks[0]->offset);
        self::assertSame(7.0, $marks[1]->offset);
        self::assertSame('charge', $marks[1]->event->label);
    }

    private function event(
        int $sequence,
        string $at,
        string $label,
        WorkflowRunEventKind $kind = WorkflowRunEventKind::Activity,
        ?string $actionKey = null,
        bool $started = false,
        bool $failed = false,
    ): WorkflowRunEvent {
        return new WorkflowRunEvent(
            $sequence,
            new \DateTimeImmutable('2026-08-29 ' . $at, new \DateTimeZone('UTC')),
            $kind,
            $label,
            [],
            $actionKey,
            $started,
            $failed,
        );
    }
}
