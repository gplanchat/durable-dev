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
        self::assertSame([], $timeline->journal(), 'array_merge() sans argument, et non une erreur');
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

    public function testASegmentSaysWhatItIsInWordsBothHostsShare(): void
    {
        // Une hachure sans légende est une devinette, et celui qui survole la barre est justement
        // celui qui veut savoir. Que les deux surfaces le disent avec les mêmes mots n'est pas de
        // la coquetterie : un exploitant qui passe de l'une à l'autre ne doit rien avoir à traduire.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:20.000', 'charge', actionKey: 'activity:act-1', started: true),
        ]);

        $title = $timeline->actions[0]->segments[0]->title;
        self::assertStringContainsString('waiting to be picked up', $title);
        self::assertStringContainsString('20.0 s', $title);
        self::assertStringContainsString('#1', $title);
        self::assertStringContainsString('#2', $title);
    }

    public function testAWorkingIntervalDoesNotClaimToBeAWait(): void
    {
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:01.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertStringNotContainsString('waiting', $timeline->actions[0]->segments[0]->title);
    }

    public function testAMarkNamesItsRankItsMomentAndItsEvent(): void
    {
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'start'),
            $this->event(2, '12:00:03.250', 'charge', actionKey: 'activity:act-1'),
        ]);

        $title = $timeline->actions[1]->events[0]->title;
        self::assertStringContainsString('#2', $title);
        self::assertStringContainsString('12:00:03.250', $title);
        self::assertStringContainsString('charge', $title);
    }

    public function testWhatTheBackendRecordedIsRenderedOnceForEverySurface(): void
    {
        // Sylius passait la charge utile à `json_encode` sans tolérance et rendait un dépliant
        // vide dès qu'un octet n'était pas de l'UTF-8. La mise en forme se décide ici, une fois.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1', details: ['orderId' => 'ORD-7']),
            $this->event(2, '12:00:01.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        $marks = $timeline->actions[0]->events;
        self::assertIsString($marks[0]->renderedDetails);
        self::assertStringContainsString('ORD-7', $marks[0]->renderedDetails);
        self::assertNull($marks[1]->renderedDetails, 'rien d\'enregistré, rien à déplier');
    }

    public function testEveryRowOfTheJournalNamesItsActionAndNotItsEvent(): void
    {
        // Seule la planification connaît le nom de l'activité : ses suites ne portent qu'un numéro,
        // et une surface en tableau affichait donc `ACTIVITY TASK STARTED` sur deux lignes sur
        // trois, là où l'exploitant cherchait `charge`.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:01.000', 'ActivityTaskStarted', actionKey: 'activity:act-1', started: true),
        ]);

        $rows = $timeline->journal();
        self::assertSame(['charge', 'charge'], array_map(static fn($row): string => $row->actionLabel, $rows));
        self::assertSame(['charge', 'ActivityTaskStarted'], array_map(static fn($row): string => $row->event->label, $rows));
    }

    public function testAnEventThatIsItsOwnActionNamesItself(): void
    {
        // Laisser la case vide ferait croire à un trou ; répéter son libellé dans les deux colonnes
        // n'apprend rien, mais c'est la vérité et non un trou.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'orderApproved', kind: WorkflowRunEventKind::Signal),
        ]);

        self::assertSame('orderApproved', $timeline->journal()[0]->actionLabel);
    }

    public function testTheJournalComesBackInRecordedOrderAndNotGroupedByAction(): void
    {
        // La frise groupe pour répondre « combien de temps » ; le journal déroule pour répondre
        // « dans quel ordre ». Rendre le second dans l'ordre du premier ferait mentir l'ordre, qui
        // est ce qu'un exploitant vient lire en premier.
        $timeline = RunTimeline::of([
            $this->event(1, '12:00:00.000', 'charge', actionKey: 'activity:act-1'),
            $this->event(2, '12:00:01.000', 'orderApproved', kind: WorkflowRunEventKind::Signal),
            $this->event(3, '12:00:02.000', 'charge', actionKey: 'activity:act-1'),
        ]);

        self::assertSame([1, 2, 3], array_map(static fn($row): int => $row->event->sequence, $timeline->journal()));
    }

    private function event(
        int $sequence,
        string $at,
        string $label,
        WorkflowRunEventKind $kind = WorkflowRunEventKind::Activity,
        ?string $actionKey = null,
        bool $started = false,
        bool $failed = false,
        array $details = [],
    ): WorkflowRunEvent {
        return new WorkflowRunEvent(
            $sequence,
            new \DateTimeImmutable('2026-08-29 ' . $at, new \DateTimeZone('UTC')),
            $kind,
            $label,
            $details,
            $actionKey,
            $started,
            $failed,
        );
    }
}
