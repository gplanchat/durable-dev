<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Versioning;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Versioning\ChangePoint;
use PHPUnit\Framework\TestCase;

/**
 * L'exécution qui a dépassé ce point **avant qu'il n'existe**.
 *
 * C'est le cas pour lequel le versioning est inventé, et le seul que le primitif ne couvrait pas.
 * Son journal ne porte aucun marqueur, parce qu'au moment où elle est passée là il n'y avait rien
 * à marquer. Lui donner le comportement neuf serait l'inverse exact de ce qu'on veut : elle a
 * commencé sur l'ancien, elle doit le finir sur l'ancien.
 *
 * La distinction se joue sur une seule question — le journal porte-t-il encore du travail que
 * cette passe n'a pas atteint ? Si oui, l'appel est dans le préfixe rejoué et l'exécution est plus
 * vieille que le point de changement. Sinon, elle y arrive pour la première fois.
 */
final class ChangePointOnAnOlderRunTest extends TestCase
{
    private const EXECUTION = 'exec-older';

    public function testARunThatPredatesTheChangePointKeepsTheOldBehaviour(): void
    {
        // Deux activités déjà enregistrées : le code d'alors ne déclarait aucun point de
        // changement. Le code d'aujourd'hui en déclare un AVANT elles.
        $context = $this->contextWithTwoRecordedActivities();

        $version = $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(
            ChangePoint::DEFAULT_VERSION,
            $version,
            "une exécution partie avant le point de changement garde l'ancien comportement",
        );
    }

    public function testItIsNotMarkedEither(): void
    {
        $store = new InMemoryEventStore();
        $this->seedTwoActivities($store);
        $before = iterator_count($store->readStream(self::EXECUTION));

        $this->context($store)->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(
            $before,
            iterator_count($store->readStream(self::EXECUTION)),
            "rien n'est écrit : la réponse se déduit de l'historique, elle ne s'y ajoute pas",
        );
    }

    public function testTheAnswerIsStableAcrossReplays(): void
    {
        $store = new InMemoryEventStore();
        $this->seedTwoActivities($store);

        $first = $this->context($store)->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);
        $second = $this->context($store)->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(ChangePoint::DEFAULT_VERSION, $first);
        self::assertSame($first, $second, 'déductible de l’historique, donc stable par construction');
    }

    public function testAPointReachedPastTheRecordedWorkIsNew(): void
    {
        // La même exécution, mais le point de changement est placé APRÈS son travail enregistré :
        // elle y arrive pour la première fois maintenant, donc elle prend le neuf.
        $context = $this->contextWithTwoRecordedActivities();
        $context->activity('chargeCard', []);
        $context->activity('shipOrder', []);

        $version = $context->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(1, $version, 'passé le travail enregistré, le point est neuf pour elle');
    }

    public function testAFreshRunIsNotMistakenForAnOldOne(): void
    {
        $version = $this->context(new InMemoryEventStore())
            ->version('ajout-remise', ChangePoint::DEFAULT_VERSION, 1);

        self::assertSame(1, $version, "un journal vide n'est pas un préfixe rejoué");
    }

    private function seedTwoActivities(InMemoryEventStore $store): void
    {
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'chargeCard', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 42));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-2', 'shipOrder', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-2', 'shipped'));
    }

    private function contextWithTwoRecordedActivities(): ExecutionContext
    {
        $store = new InMemoryEventStore();
        $this->seedTwoActivities($store);

        return $this->context($store);
    }

    private function context(InMemoryEventStore $store): ExecutionContext
    {
        return new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
        );
    }
}
