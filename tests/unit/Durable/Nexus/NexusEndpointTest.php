<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Nexus\NexusEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Un cas par verdict observé sur Temporal 1.31.2 (tâche 1.1), et rien d'autre.
 *
 * Contrairement à {@see \Gplanchat\Durable\TaskQueue}, cet objet n'est **pas** plus strict que le
 * serveur. Il n'a pas à l'être : une file mal nommée est acceptée puis n'est jamais servie, en
 * silence, alors qu'un endpoint mal nommé est refusé net à la création. Il n'y a donc pas de
 * panne muette à prévenir, et inventer une règle de plus ne ferait que refuser des noms que le
 * serveur accepte.
 */
final class NexusEndpointTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedByTheServerRegex(): iterable
    {
        yield 'blanc seul' => [' '];
        yield 'espace en tête' => [' probe'];
        yield 'espace en queue' => ['probe '];
        yield 'tabulation interne' => ["pro\tbe"];
        yield 'saut de ligne interne' => ["pro\nbe"];
        yield 'caractère de contrôle' => ["pro\x00be"];
        yield 'souligné' => ['pro_be'];
        yield 'point' => ['pro.be'];
        yield 'barre oblique' => ['pro/be'];
        yield 'lettre accentuée' => ['probé'];
        yield 'chiffre en tête' => ['1probe'];
        yield 'tiret en tête' => ['-probe'];
        yield 'tiret en queue' => ['probe-'];
        // Le motif exige un premier *et* un dernier caractère : une lettre seule n'en a qu'un.
        yield 'lettre seule' => ['a'];
    }

    #[DataProvider('refusedByTheServerRegex')]
    public function testANameTheServerRefusesIsRefusedHere(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not match/');

        NexusEndpoint::named($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedByTheServer(): iterable
    {
        yield 'deux lettres' => ['ab'];
        yield 'lettres, chiffres, tirets internes, deux casses' => ['Probe-Nexus-42'];
        yield '200 caractères' => ['a' . str_repeat('b', 198) . 'c'];
    }

    #[DataProvider('acceptedByTheServer')]
    public function testANameTheServerAcceptsIsAcceptedHere(string $name): void
    {
        self::assertSame($name, NexusEndpoint::named($name)->name());
    }

    public function testAnEmptyNameIsUnsetRatherThanMalformed(): void
    {
        // La distinction est celle du serveur : « endpoint name not set » d'un côté, le refus par
        // le motif de l'autre. Deux fautes différentes méritent deux messages différents.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not set/');

        NexusEndpoint::named('');
    }

    public function testTwoHundredAndOneCharactersExceedTheServerLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/200/');

        NexusEndpoint::named('a' . str_repeat('b', 199) . 'c');
    }

    public function testItCoercesWhatTheCallerHasAtHand(): void
    {
        $endpoint = NexusEndpoint::named('probe-nexus');

        self::assertSame($endpoint, NexusEndpoint::from($endpoint));
        self::assertTrue(NexusEndpoint::from('probe-nexus')->equals($endpoint));
        self::assertNull(NexusEndpoint::fromNullable(null));
        self::assertSame('probe-nexus', (string) $endpoint);
    }
}
