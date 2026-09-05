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
 * La garde de divergence comparait le **nom** de l'activité au slot, et rien d'autre.
 *
 * Une charge recalculée autrement au replay passait donc en silence : le journal servait l'ancien
 * résultat, la charge fraîche partait à la poubelle, et l'exécution se terminait en succès en
 * ayant menti sur ce qu'elle avait demandé. Ce fichier tient les deux moitiés de la règle — ce que
 * la garde doit refuser, et tout ce qu'elle doit continuer de laisser passer, parce qu'une garde
 * qui arrête une exécution saine coûte plus cher que le trou qu'elle bouche.
 */
final class ActivityPayloadDivergenceTest extends TestCase
{
    private const EXECUTION = 'exec-payload-guard';

    public function testTheSameActivityWithAnotherPayloadIsRefused(): void
    {
        $store = $this->journalWith(['city' => 'Paris']);
        $context = $this->context($store);

        $this->expectException(WorkflowTaskFailure::class);
        $this->expectExceptionMessageMatches('/payload changed/');

        $context->activity('weather', ['city' => 'Lyon']);
    }

    public function testTheMessageNamesTheSlotTheActivityAndTheCause(): void
    {
        $store = $this->journalWith(['nonce' => 1]);
        $context = $this->context($store);

        try {
            $context->activity('weather', ['nonce' => 2]);
            self::fail('La garde aurait dû refuser cette charge.');
        } catch (WorkflowTaskFailure $refusal) {
            $message = $refusal->getMessage();
            self::assertStringContainsString('activity slot 0', $message);
            self::assertStringContainsString('"weather"', $message, 'le nom concorde : le dire évite de chercher un slot décalé');
            self::assertStringContainsString('non-deterministic workflow code', $message);
            self::assertStringNotContainsString('different version of the workflow', $message, 'ce message-là enverrait vers ChangePoint, qui n\'y peut rien');
        }
    }

    public function testAFaithfulReplayPasses(): void
    {
        $store = $this->journalWith(['city' => 'Paris']);
        $context = $this->context($store);

        $awaitable = $context->activity('weather', ['city' => 'Paris']);

        self::assertTrue($awaitable->isSettled(), 'le slot se résout : la charge est celle du journal');
    }

    public function testKeyOrderIsNotADivergence(): void
    {
        // Un objet JSON n'a pas d'ordre. Le voir changer ne prouve rien, et l'ériger en divergence
        // arrêterait des exécutions dont la charge est identique.
        $store = $this->journalWith(['b' => 2, 'a' => 1]);
        $context = $this->context($store);

        $awaitable = $context->activity('weather', ['a' => 1, 'b' => 2]);

        self::assertTrue($awaitable->isSettled());
    }

    public function testAListThatChangedOrderIsADivergence(): void
    {
        // Dans une liste, l'ordre *est* l'information.
        $store = $this->journalWith(['cities' => ['Paris', 'Lyon']]);
        $context = $this->context($store);

        $this->expectException(WorkflowTaskFailure::class);

        $context->activity('weather', ['cities' => ['Lyon', 'Paris']]);
    }

    public function testAnEmptyRecordedPayloadIsStillCompared(): void
    {
        // `[]` est une activité planifiée sans argument, pas « rien d'enregistré ». La confondre
        // avec null est le piège de findSideEffectForSlot(), et il ne se reproduit pas ici.
        $store = $this->journalWith([]);
        $context = $this->context($store);

        $this->expectException(WorkflowTaskFailure::class);

        $context->activity('weather', ['city' => 'Paris']);
    }

    public function testAnObjectTheJournalCannotSeeIntoDoesNotDiverge(): void
    {
        // Le style de la maison : des DTO en lecture seule à propriétés privées. Le journal n'en
        // retient rien — `{}` à l'aller, `[]` au retour. Sans normalisation des deux côtés, toute
        // exécution qui en porte un divergerait à chaque reprise. C'est le faux positif qui aurait
        // arrêté des workflows sains, et il est mesuré ici pour qu'il ne revienne pas.
        $freshPayload = [
            'amount' => new class (90, 'EUR') {
                public function __construct(private int $cents, private string $currency) {}
            },
            'ref' => 'A-1',
        ];

        // L'aller-retour n'est pas supposé, il est fait : ce que `DbalEventStore` écrit, puis ce
        // que `EventDataMapper` relit. Écrire son résultat en dur ferait passer ce test le jour où
        // l'aplatissement changerait — c'est-à-dire le jour où le faux positif reviendrait.
        $throughTheDatabase = json_decode(
            json_encode(
                (new ActivityScheduled(self::EXECUTION, 'act-1', 'charge', $freshPayload))->payload(),
                \JSON_THROW_ON_ERROR,
            ),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        )['payload'];

        self::assertNotSame($freshPayload, $throughTheDatabase, 'sans aplatissement, ce test ne prouve rien');

        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'charge', $throughTheDatabase));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', 'ok'));

        $context = $this->context($store);

        // Ce que le code recalcule au replay : l'objet, toujours vivant.
        $awaitable = $context->activity('charge', $freshPayload);

        self::assertTrue($awaitable->isSettled(), 'un replay fidèle ne doit pas mourir sur un objet opaque');
    }

    public function testAnIncomparablePayloadWaivesTheGuard(): void
    {
        // Une ressource ne s'encode pas. La garde n'a alors rien à comparer : elle se tait plutôt
        // que d'accuser une charge qu'elle ne sait pas lire.
        $store = $this->journalWith(['handle' => null]);
        $context = $this->context($store);

        $handle = fopen('php://memory', 'r');
        self::assertIsResource($handle);

        $awaitable = $context->activity('weather', ['handle' => $handle]);

        self::assertTrue($awaitable->isSettled());
        fclose($handle);
    }

    public function testAHistoryWithoutARecordedSlotIsNotADivergence(): void
    {
        // Un slot que personne n'a enregistré, c'est un workflow qui grandit — pas une divergence.
        $context = $this->context(new InMemoryEventStore());

        $awaitable = $context->activity('weather', ['city' => 'Paris']);

        self::assertFalse($awaitable->isSettled(), 'le slot est neuf : il part en planification');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function journalWith(array $payload): InMemoryEventStore
    {
        $store = new InMemoryEventStore();
        $store->append(new ActivityScheduled(self::EXECUTION, 'act-1', 'weather', $payload));
        $store->append(new ActivityCompleted(self::EXECUTION, 'act-1', '22°C'));

        return $store;
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
