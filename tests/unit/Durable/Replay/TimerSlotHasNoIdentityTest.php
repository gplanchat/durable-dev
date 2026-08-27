<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Replay;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use PHPUnit\Framework\TestCase;

/**
 * Le trou de la garde, épinglé plutôt que comblé — DUR042.
 *
 * Les quatre autres types de slot portent une identité que l'historique enregistre déjà : le nom
 * d'une activité, le triplet d'une opération Nexus, le type d'un enfant. **Un minuteur n'en porte
 * aucune.** `TimerScheduled` retient `clock() + delay`, une échéance **absolue** qu'un replay ne
 * peut pas recalculer, et le délai d'origine n'est enregistré nulle part ; `summary` est fourni par
 * l'auteur et vaut la chaîne vide par défaut.
 *
 * Ce fichier existe pour que ce trou se remarque. Sans lui, la première personne qui lit la garde
 * y verrait un oubli et « corrigerait » en comparant l'échéance — ce qui ferait diverger un replay
 * parfaitement fidèle, puisque l'échéance recalculée maintenant n'est jamais celle d'alors.
 *
 * Il dit aussi ce qui **borne** le trou, et c'est le plus utile des deux : un décalage de slots ne
 * passe inaperçu que s'il ne touche que des minuteurs.
 */
final class TimerSlotHasNoIdentityTest extends TestCase
{
    private const EXECUTION = 'exec-timers';

    public function testAChangedDurationReplaysWithoutBeingReported(): void
    {
        // Le comportement tel qu'il est, et non tel qu'on le souhaiterait : le slot se résout,
        // aucune divergence n'est signalée. C'est le trou.
        $store = new InMemoryEventStore();
        $store->append(new TimerScheduled(self::EXECUTION, 'timer-1', 1_000_000.0, ''));
        $store->append(new TimerCompleted(self::EXECUTION, 'timer-1'));

        $context = $this->context($store);
        $awaitable = $context->timer(Duration::seconds(3600.0));

        self::assertTrue($awaitable->isSettled(), 'Le slot de minuteur se résout : rien à comparer.');
    }

    public function testTheRecordedDeadlineIsAbsoluteAndSaysNothingAboutTheDelay(): void
    {
        // La raison du trou, épinglée : deux minuteurs de durées différentes, planifiés à des
        // instants différents, peuvent porter la même échéance. L'échéance n'identifie donc pas
        // l'appel — c'est ce qui interdit de la comparer.
        $store = new InMemoryEventStore();
        $store->append(new TimerScheduled(self::EXECUTION, 'timer-1', 1_000_000.0, ''));

        $events = iterator_to_array($store->readStream(self::EXECUTION));
        $recorded = $events[0];

        self::assertInstanceOf(TimerScheduled::class, $recorded);
        self::assertSame(1_000_000.0, $recorded->scheduledAt(), "L'échéance est un instant, pas une durée.");
        self::assertSame('', $recorded->summary(), 'Et le seul champ libre est facultatif.');
    }

    public function testAShiftThatAlsoMovesAnActivityIsStillCaught(): void
    {
        // Ce qui borne le trou. Un décalage de slots n'échappe à la garde que s'il ne touche
        // **que** des minuteurs ; dès qu'une activité bouge avec, le nom la rattrape.
        $store = new InMemoryEventStore();
        $store->append(new TimerScheduled(self::EXECUTION, 'timer-1', 1_000_000.0, ''));
        $store->append(new TimerCompleted(self::EXECUTION, 'timer-1'));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'chargeCard', []));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 42));

        $context = $this->context($store);
        $context->timer(Duration::seconds(3600.0));

        $this->expectException(WorkflowTaskFailure::class);
        $context->activity('reserveStock', []);
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
