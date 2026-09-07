<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One case per verdict observed on Temporal 1.31.2 (probe §1.1 and §1.2), and nothing else.
 *
 * The server is permissive about everything **except case**: empty key, empty value, whitespace at
 * the edges, newline, space in the key, a thousand characters — everything is accepted as it is.
 * Being stricter than it would reject headers it carries without flinching.
 *
 * One single thing escapes it, and it is silent: it lowercases the keys, so that two keys
 * differing only by case collide — two headers go in, one comes out, with no error and no trace.
 * That is the only thing this object has to prevent.
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
        // The server lowercases without saying so. Returning the key as it was sent would make
        // reading it back a lie: the caller would believe it had set `X-Correlation`.
        $headers = NexusOperationHeaders::of(['X-Correlation' => 'abc-123', 'X-TOUT-MAJ' => 'v']);

        self::assertSame(['x-correlation' => 'abc-123', 'x-tout-maj' => 'v'], $headers->toArray());
    }

    public function testTwoKeysCollidingOnCaseAreRefused(): void
    {
        // The silent failure: on the server side, two headers go in and one comes out. Here the
        // caller asks for something the server cannot do and would not say.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/x-choc/');

        NexusOperationHeaders::of(['X-Choc' => 'majuscule', 'x-choc' => 'minuscule']);
    }

    public function testTheCollisionMessageNamesBothSpellings(): void
    {
        // A message naming only the folded key leaves you hunting for which of the two spellings
        // in the calling code was at fault.
        try {
            NexusOperationHeaders::of(['X-Choc' => 'a', 'x-choc' => 'b']);
            self::fail('The collision should have been refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('X-Choc', $e->getMessage());
            self::assertStringContainsString('x-choc', $e->getMessage());
        }
    }

    public function testAnIdenticalKeyTwiceIsNotACollision(): void
    {
        // PHP cannot produce this case in a literal, but a built array can: the same key twice
        // is not an ambiguity, it is a single entry.
        self::assertSame(['x-a' => 'deux'], NexusOperationHeaders::of(['x-a' => 'deux'])->toArray());
    }

    public function testEmptyIsEmptyAndSaysSo(): void
    {
        self::assertTrue(NexusOperationHeaders::none()->isEmpty());
        self::assertSame([], NexusOperationHeaders::none()->toArray());
        self::assertFalse(NexusOperationHeaders::of(['x-a' => 'v'])->isEmpty());
    }
}
