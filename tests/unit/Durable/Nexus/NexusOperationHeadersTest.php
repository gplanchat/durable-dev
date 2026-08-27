<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Un cas par verdict observé sur Temporal 1.31.2 (sonde §1.1 et §1.2), et rien d'autre.
 *
 * Le serveur est permissif sur tout **sauf la casse** : clé vide, valeur vide, blancs en bord,
 * saut de ligne, espace dans la clé, mille caractères — tout est accepté tel quel. Être plus
 * strict que lui rejetterait des en-têtes qu'il porte sans broncher.
 *
 * Une seule chose lui échappe, et elle est muette : il minuscule les clés, si bien que deux clés
 * ne différant que par la casse entrent en collision — deux en-têtes entrent, un seul sort, sans
 * erreur ni trace. C'est la seule chose que cet objet ait à empêcher.
 */
final class NexusOperationHeadersTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function acceptedByTheServer(): iterable
    {
        yield 'ordinaire' => [['x-correlation' => 'abc-123']];
        yield 'valeur vide' => [['x-vide' => '']];
        yield 'clé vide' => [['' => 'valeur']];
        yield 'blancs en bord de valeur' => [['x-bord' => ' abc ']];
        yield 'saut de ligne dans la valeur' => [['x-nl' => "a\nb"]];
        yield 'espace dans la clé' => [['x avec espace' => 'v']];
        yield 'valeur de 1000 caractères' => [['x-long' => str_repeat('a', 1000)]];
        yield 'deux en-têtes' => [['x-un' => '1', 'x-deux' => '2']];
    }

    #[DataProvider('acceptedByTheServer')]
    public function testWhatTheServerAcceptsIsAcceptedHere(array $headers): void
    {
        self::assertSame($headers, NexusOperationHeaders::of($headers)->toArray());
    }

    public function testAKeyIsLoweredSoTheCallerHoldsWhatTheServerKeeps(): void
    {
        // Le serveur minuscule sans le dire. Rendre la clé telle qu'envoyée ferait mentir la
        // relecture : l'appelant croirait avoir posé `X-Correlation`.
        $headers = NexusOperationHeaders::of(['X-Correlation' => 'abc-123', 'X-TOUT-MAJ' => 'v']);

        self::assertSame(['x-correlation' => 'abc-123', 'x-tout-maj' => 'v'], $headers->toArray());
    }

    public function testTwoKeysCollidingOnCaseAreRefused(): void
    {
        // La panne muette : côté serveur, deux en-têtes entrent et un seul sort. Ici l'appelant
        // demande quelque chose que le serveur ne sait pas faire et ne dirait pas.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/x-choc/');

        NexusOperationHeaders::of(['X-Choc' => 'majuscule', 'x-choc' => 'minuscule']);
    }

    public function testTheCollisionMessageNamesBothSpellings(): void
    {
        // Un message qui ne nomme que la clé rabattue laisse chercher laquelle des deux graphies
        // du code appelant a fauté.
        try {
            NexusOperationHeaders::of(['X-Choc' => 'a', 'x-choc' => 'b']);
            self::fail('La collision aurait dû être refusée.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('X-Choc', $e->getMessage());
            self::assertStringContainsString('x-choc', $e->getMessage());
        }
    }

    public function testAnIdenticalKeyTwiceIsNotACollision(): void
    {
        // PHP ne peut pas produire ce cas dans un littéral, mais un tableau construit le peut :
        // deux fois la même clé n'est pas une ambiguïté, c'est une seule entrée.
        self::assertSame(['x-a' => 'deux'], NexusOperationHeaders::of(['x-a' => 'deux'])->toArray());
    }

    public function testEmptyIsEmptyAndSaysSo(): void
    {
        self::assertTrue(NexusOperationHeaders::none()->isEmpty());
        self::assertSame([], NexusOperationHeaders::none()->toArray());
        self::assertFalse(NexusOperationHeaders::of(['x-a' => 'v'])->isEmpty());
    }
}
