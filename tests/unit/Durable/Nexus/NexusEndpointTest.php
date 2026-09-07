<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Nexus\NexusEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One case per verdict observed on Temporal 1.31.2 (task 1.1), and nothing else.
 *
 * Unlike {@see \Gplanchat\Durable\TaskQueue}, this object is **not** stricter than the server. It
 * does not have to be: a badly named queue is accepted and then never served, in silence, whereas
 * a badly named endpoint is refused outright at creation. There is therefore no silent failure to
 * prevent, and inventing one more rule would only refuse names the server accepts.
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
        // The pattern requires a first *and* a last character: a lone letter only has one.
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
        // The distinction is the server's: "endpoint name not set" on one side, the refusal by
        // the pattern on the other. Two different mistakes deserve two different messages.
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
