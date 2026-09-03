<?php

declare(strict_types=1);

namespace Tests\Unit\Durable;

use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Versioning\ChangePoint;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Un effet de bord est enregistré ou il ne l'est pas. C'est un état, et un état ne se déduit pas
 * d'une valeur de retour : la valeur enregistrée peut légitimement être `null`, `false` ou `0`.
 *
 * Ces cas fixent la garantie que `sideEffect()` existe pour offrir — la closure ne tourne qu'une
 * fois, quoi qu'elle rende — et le corollaire qu'on oublie plus facilement : un journal qui ne
 * grossit pas d'un événement à chaque passe de rejeu.
 */
final class SideEffectSlotPresenceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function valeursQuiSeConfondentAvecLAbsence(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'zéro' => [0];
        yield 'chaîne vide' => [''];
        yield 'liste vide' => [[]];
    }

    #[DataProvider('valeursQuiSeConfondentAvecLAbsence')]
    public function testUneClosureQuiRendUneValeurFausseNeTourneQuUneFois(mixed $valeur): void
    {
        $store = new InMemoryEventStore();
        $appels = 0;

        $workflow = static function (WorkflowEnvironment $wf) use ($valeur, &$appels): mixed {
            return $wf->sideEffect(static function () use ($valeur, &$appels): mixed {
                ++$appels;

                return $valeur;
            });
        };

        self::executer($store, 'exec-1', $workflow);
        self::assertSame(1, $appels, 'la première passe exécute la closure');

        self::executer($store, 'exec-1', $workflow);
        self::assertSame(1, $appels, 'la passe de rejeu doit relire le résultat, pas le recalculer');
    }

    #[DataProvider('valeursQuiSeConfondentAvecLAbsence')]
    public function testLeJournalNeGrossitPasDUnEvenementParPasse(mixed $valeur): void
    {
        $store = new InMemoryEventStore();

        $workflow = static fn(WorkflowEnvironment $wf): mixed => $wf->sideEffect(static fn(): mixed => $valeur);

        self::executer($store, 'exec-1', $workflow);
        self::executer($store, 'exec-1', $workflow);
        self::executer($store, 'exec-1', $workflow);

        self::assertSame(
            1,
            self::compterEffetsDeBord($store, 'exec-1'),
            'trois passes sur une seule instruction sideEffect() doivent laisser un seul événement',
        );
    }

    #[DataProvider('valeursQuiSeConfondentAvecLAbsence')]
    public function testLaValeurRelueEstLaValeurEnregistree(mixed $valeur): void
    {
        $store = new InMemoryEventStore();

        $workflow = static fn(WorkflowEnvironment $wf): mixed => $wf->sideEffect(static fn(): mixed => $valeur);

        self::assertSame($valeur, self::executer($store, 'exec-1', $workflow));
        self::assertSame($valeur, self::executer($store, 'exec-1', $workflow), 'le rejeu rend la même valeur');
    }

    /**
     * Les slots restent alignés : un effet de bord « faux » ne doit pas décaler celui d'après.
     */
    public function testUnEffetDeBordFauxNeDecalePasLeSlotSuivant(): void
    {
        $store = new InMemoryEventStore();

        $workflow = static fn(WorkflowEnvironment $wf): array => [
            'premier' => $wf->sideEffect(static fn(): mixed => null),
            'second' => $wf->sideEffect(static fn(): string => 'après'),
        ];

        self::assertSame(['premier' => null, 'second' => 'après'], self::executer($store, 'exec-1', $workflow));
        self::assertSame(['premier' => null, 'second' => 'après'], self::executer($store, 'exec-1', $workflow));
        self::assertSame(2, self::compterEffetsDeBord($store, 'exec-1'));
    }

    private static function executer(InMemoryEventStore $store, string $executionId, \Closure $workflow): mixed
    {
        $runner = new InMemoryWorkflowRunner(
            $store,
            new InMemoryActivityTransport(),
            new RegistryActivityExecutor(),
            0,
            new WorkflowRegistry(),
        );

        return $runner->run($executionId, $workflow);
    }

    private static function compterEffetsDeBord(InMemoryEventStore $store, string $executionId): int
    {
        $total = 0;
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof SideEffectRecorded) {
                ++$total;
            }
        }

        return $total;
    }

    /**
     * L'appelant frère. `version()` demande « suis-je en train de rejouer ? » à
     * `hasRecordedWorkAhead()`, qui interrogeait les quatre autres types de slot et pas les effets
     * de bord, faute de pouvoir en lire la présence sans en lire la valeur.
     *
     * Une exécution dont le travail restant devant elle n'est fait que d'effets de bord était donc
     * vue comme arrivée au bout de son historique. Elle prenait la branche neuve **en plein
     * rejeu**, et écrivait son marqueur de version au milieu d'une histoire écrite avant que le
     * point de changement existe — ce que `version()` est précisément là pour empêcher.
     */
    public function testUnTravailRestantFaitDEffetsDeBordRetientLAncienneVersion(): void
    {
        $store = new InMemoryEventStore();

        // Le code d'avant : deux effets de bord, aucun point de changement.
        $avant = static fn(WorkflowEnvironment $wf): array => [
            'premier' => $wf->sideEffect(static fn(): mixed => null),
            'second' => $wf->sideEffect(static fn(): string => 'après'),
        ];
        self::executer($store, 'exec-1', $avant);

        // Le code d'après, sur la même exécution : un point de changement s'est glissé entre les
        // deux effets de bord, et le second est encore devant.
        $apres = static fn(WorkflowEnvironment $wf): array => [
            'premier' => $wf->sideEffect(static fn(): mixed => null),
            'version' => $wf->version('changement-1', 1, 3),
            'second' => $wf->sideEffect(static fn(): string => 'après'),
        ];

        self::assertSame(
            ChangePoint::DEFAULT_VERSION,
            self::executer($store, 'exec-1', $apres)['version'],
            'une exécution en vol garde l\'ancien comportement tant qu\'il lui reste du journal à rejouer',
        );
    }
}
