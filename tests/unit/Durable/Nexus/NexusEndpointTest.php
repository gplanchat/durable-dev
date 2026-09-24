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
        yield 'whitespace only' => [' '];
        yield 'leading space' => [' probe'];
        yield 'trailing space' => ['probe '];
        yield 'internal tab' => ["pro\tbe"];
        yield 'internal newline' => ["pro\nbe"];
        yield 'control character' => ["pro\x00be"];
        yield 'underscore' => ['pro_be'];
        yield 'dot' => ['pro.be'];
        yield 'slash' => ['pro/be'];
        yield 'accented letter' => ['probé'];
        yield 'leading digit' => ['1probe'];
        yield 'leading hyphen' => ['-probe'];
        yield 'trailing hyphen' => ['probe-'];
        // The pattern requires a first *and* a last character: a lone letter only has one.
        yield 'single letter' => ['a'];
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
        yield 'two letters' => ['ab'];
        yield 'letters, digits, internal hyphens, both cases' => ['Probe-Nexus-42'];
        yield '200 characters' => ['a' . str_repeat('b', 198) . 'c'];
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
