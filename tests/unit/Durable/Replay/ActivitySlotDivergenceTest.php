<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Replay;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use PHPUnit\Framework\TestCase;

/**
 * Le journal dit qu'on a appelé A ; le code déployé demande B à la même position.
 *
 * Mesuré avant d'être corrigé (sonde 1.1 de `workflow-replay-divergence-guard`, serveur
 * `start-dev` 1.31.2) : le slot se résolvait **silencieusement** avec le résultat enregistré de
 * l'autre appel, et l'exécution se terminait **en succès** en le portant. Rien dans l'historique ne
 * marquait la divergence, et aucun moment ultérieur ne s'en apercevait.
 *
 * DUR003 décrivait pourtant cette comparaison comme existante. Elle n'existait pas.
 */
final class ActivitySlotDivergenceTest extends TestCase
{
    private const EXECUTION = 'exec-divergence';

    public function testASwappedActivityIsRefusedInsteadOfResolved(): void
    {
        $context = $this->contextReplaying('chargeCard', 'act-1', 42);

        $this->expectException(WorkflowTaskFailure::class);
        $context->activity('reserveStock', ['sku' => 'ABC']);
    }

    public function testTheRefusalNamesBothSidesAndWhereItHappened(): void
    {
        $context = $this->contextReplaying('chargeCard', 'act-1', 42);

        try {
            $context->activity('reserveStock', ['sku' => 'ABC']);
            self::fail('La divergence aurait dû être refusée.');
        } catch (WorkflowTaskFailure $e) {
            $message = $e->getMessage();
        }

        // Sans ces trois-là, le message coûte un bisect à qui le lit.
        self::assertStringContainsString('chargeCard', $message, "Le nom enregistré manque : on ne sait pas ce que l'historique tient.");
        self::assertStringContainsString('reserveStock', $message, 'Le nom demandé manque : on ne sait pas ce que le code voulait.');
        self::assertStringContainsString(self::EXECUTION, $message, "L'exécution manque : la première chose qu'on fait est d'ouvrir son historique.");
    }

    public function testTheMessageCarriesTheFivePartsNeededToAct(): void
    {
        // Ce que quelqu'un fait avec ce message, dans l'ordre : il ouvre l'historique de cette
        // exécution, va à ce slot, et compare les deux noms. Les cinq morceaux sont donc un
        // contrat, pas une formulation — c'est pour ça qu'ils ont leur test.
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'chargeCard', ['sku' => 'ABC']));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 42));
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-2', 'shipOrder', ['sku' => 'ABC']));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-2', 'shipped'));

        $context = new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
        );
        $context->activity('chargeCard', ['sku' => 'ABC']);

        try {
            $context->activity('reserveStock', ['sku' => 'ABC']);
            self::fail('La divergence aurait dû être refusée.');
        } catch (WorkflowTaskFailure $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('activity', $message, 'le type de slot');
        self::assertStringContainsString('slot 1', $message, "l'index, et celui du second appel — pas 0 par accident");
        self::assertStringContainsString(self::EXECUTION, $message, "l'exécution");
        self::assertStringContainsString('"shipOrder"', $message, 'ce que le journal tient à CE slot');
        self::assertStringContainsString('"reserveStock"', $message, 'ce que le code a demandé');
    }

    public function testAnUnchangedActivityStillResolvesFromHistory(): void
    {
        $context = $this->contextReplaying('chargeCard', 'act-1', 42);

        $awaitable = $context->activity('chargeCard', ['sku' => 'ABC']);

        self::assertTrue($awaitable->isSettled(), 'Le slot enregistré doit toujours se résoudre : la garde ne doit rien coûter au cas normal.');
    }

    public function testASlotNobodyRecordedIsNotADivergence(): void
    {
        // Deuxième appel du workflow, premier passage : rien à cette position, donc rien à
        // comparer. Une garde qui refuserait ici casserait tout workflow qui grandit.
        $context = $this->contextReplaying('chargeCard', 'act-1', 42);
        $context->activity('chargeCard', ['sku' => 'ABC']);

        $second = $context->activity('shipOrder', ['sku' => 'ABC']);

        self::assertFalse($second->isSettled(), 'Un slot neuf est planifié, pas refusé.');
    }

    public function testAnUnrecordedNameIsNotADivergence(): void
    {
        // Un historique qui ne porte pas le nom de l'activité ne dit rien sur ce slot. La garde
        // ne peut pas comparer, donc elle laisse passer : c'est le trou annoncé, pas un refus.
        // Sans cette règle, la garde se déclencherait sur chaque slot dont l'identité manque —
        // exactement le contraire de ce qu'on lui demande.
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', '', ['sku' => 'ABC']));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 42));

        $context = new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
        );

        $awaitable = $context->activity('reserveStock', ['sku' => 'ABC']);

        self::assertTrue($awaitable->isSettled(), 'Sans identité enregistrée, le slot se résout comme avant.');
    }

    private function contextReplaying(string $recordedName, string $activityId, mixed $result): ExecutionContext
    {
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, $activityId, $recordedName, ['sku' => 'ABC']));
        $store->append(new ActivityCompleted(self::EXECUTION, $activityId, $result));

        return new ExecutionContext(
            self::EXECUTION,
            new EventStoreHistorySource($store, self::EXECUTION),
            new EventStoreCommandBuffer($store, new NoopActivityTransport(), self::EXECUTION),
        );
    }
}
