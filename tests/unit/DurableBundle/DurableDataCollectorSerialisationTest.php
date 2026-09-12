<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une charge utile de workflow est de la donnée métier : n'importe quoi peut s'y trouver.
 *
 * Le profileur, lui, doit être **stockable** — le `Profiler` sérialise le profil entier pour
 * l'écrire. Une valeur qui ne survit pas à `serialize()` dans une charge utile ne casse donc pas
 * le panneau Durable : elle casse le profil de la requête, panneaux des autres bundles compris.
 *
 * D'où le contrat de ces cas : ce que le collecteur range doit toujours pouvoir être sérialisé,
 * quoi qu'on lui donne à observer.
 */
final class DurableDataCollectorSerialisationTest extends TestCase
{
    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function valeursQuiNeSeSerialisentPas(): iterable
    {
        yield 'closure' => [static fn(): int => 1];
        yield 'ressource' => [fopen('php://memory', 'rb')];
        yield 'objet anonyme portant une closure' => [new class {
            public \Closure $callback;

            public function __construct()
            {
                $this->callback = static fn(): int => 1;
            }
        }];
    }

    #[DataProvider('valeursQuiNeSeSerialisentPas')]
    public function testLeProfilResteStockableQuoiQueLaChargeUtilePorte(mixed $valeur): void
    {
        $collector = self::collectorAyantObserve(['commande' => 'X-1', 'hostile' => $valeur]);

        $serialise = serialize($collector);

        self::assertIsString($serialise);
        self::assertInstanceOf(DurableDataCollector::class, unserialize($serialise));
    }

    /**
     * Le reste de la charge utile est ce que l'exploitant est venu lire ; une valeur qui ne se
     * rend pas ne doit pas l'emporter avec elle.
     */
    public function testCeQuiEstLisibleDansLaChargeUtileEstConserve(): void
    {
        $collector = self::collectorAyantObserve([
            'commande' => 'X-1',
            'montant' => 1250,
            'hostile' => static fn(): int => 1,
        ]);

        $rendu = json_encode(unserialize(serialize($collector))->getTimeline());

        self::assertStringContainsString('X-1', (string) $rendu);
        self::assertStringContainsString('1250', (string) $rendu);
    }

    /**
     * Le journal peut porter des octets qui ne sont pas du texte valide. `json_encode` rend alors
     * `false`, et le gabarit affiche un vide là où il y avait une charge utile.
     */
    public function testUneChargeUtileBinaireResteAffichable(): void
    {
        $collector = self::collectorAyantObserve(['blob' => "\xB1\x31\xFE"]);

        $rendu = json_encode(unserialize(serialize($collector))->getTimeline());

        self::assertIsString($rendu, 'une charge utile binaire ne doit pas rendre le panneau vide');
    }

    /**
     * La barrière ne doit rien déformer de ce qui passait déjà : le gabarit lit des clés précises,
     * et un aller-retour JSON qui transformerait une liste en objet les casserait en silence.
     */
    public function testUneChargeUtileOrdinaireTraverseSansEtreDeformee(): void
    {
        $payload = [
            'commande' => 'X-1',
            'montant' => 1250,
            'remise' => 0.15,
            'urgent' => false,
            'lignes' => ['a', 'b'],
            'client' => ['id' => 7, 'nom' => 'Dupont'],
            'note' => null,
        ];

        $timeline = self::collectorAyantObserve($payload)->getTimeline();

        self::assertSame($payload, $timeline[0]['payload'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function collectorAyantObserve(array $payload): DurableDataCollector
    {
        $trace = new DurableExecutionTrace();
        $trace->onWorkflowDispatchRequested('exec-1', 'Commande', $payload, false, 'async');

        $collector = new DurableDataCollector($trace, new InMemoryWorkflowMetadataStore(), new InMemoryEventStore());
        $collector->collect(new Request(), new Response());

        return $collector;
    }
}
