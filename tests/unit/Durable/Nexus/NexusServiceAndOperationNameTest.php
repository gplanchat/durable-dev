<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The verdicts of probe §1.1 (the service/operation half), made impossible to suffer.
 *
 * The server validates none of them: empty, whitespace, tab, control character, a thousand
 * characters — everything is accepted and recorded verbatim, and then the operation waits for a
 * handler that will never match, without a single line of error. This is the silent failure of
 * {@see \Gplanchat\Durable\TaskQueue}, and it calls for the same remedy: being stricter than the
 * server about what can only be a mistake.
 *
 * Unlike {@see \Gplanchat\Durable\Nexus\NexusEndpoint}, which the server refuses outright and
 * which therefore has nothing to invent.
 *
 * @see tests/integration/Temporal/NexusServiceAndOperationNameRulesTest.php
 */
final class NexusServiceAndOperationNameTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function faults(): iterable
    {
        yield 'vide' => [''];
        yield 'un espace' => [' '];
        yield 'entièrement blanc' => ["\t \n"];
        yield 'espace en tête' => [' svc'];
        yield 'espace en fin' => ['svc '];
        yield 'tabulation interne' => ["sv\tc"];
        yield 'saut de ligne interne' => ["sv\nc"];
        yield 'caractère de contrôle' => ["sv\x01c"];
    }

    #[DataProvider('faults')]
    public function testAServiceNameRefusesWhatCanOnlyBeAMistake(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        NexusService::named($name);
    }

    #[DataProvider('faults')]
    public function testAnOperationNameRefusesWhatCanOnlyBeAMistake(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        NexusOperationName::named($name);
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedNames(): iterable
    {
        // The server imposes no alphabet: anything that is not an obvious mistake goes through.
        yield 'simple' => ['checkout'];
        yield 'point' => ['com.example.checkout'];
        yield 'barre oblique' => ['example/checkout'];
        yield 'underscore' => ['my_service'];
        yield 'majuscules' => ['CheckoutService'];
        yield 'accentué' => ['facturé'];
        yield 'une lettre' => ['s'];
        yield 'mille caractères' => ['x1000'];
    }

    #[DataProvider('acceptedNames')]
    public function testWhatTheServerAcceptsAndIsNotAMistakeStaysAccepted(string $name): void
    {
        $name = 'x1000' === $name ? str_repeat('x', 1000) : $name;

        self::assertSame($name, NexusService::named($name)->name());
        self::assertSame($name, NexusOperationName::named($name)->name());
    }

    public function testNoLengthLimitIsInventedBecauseNoneWasObserved(): void
    {
        // §1.4: do not write an invariant that was not observed. A thousand characters were
        // accepted by the server and no upper bound was found — so we do not make one up.
        $long = str_repeat('o', 5_000);

        self::assertSame($long, NexusOperationName::named($long)->name());
    }

    public function testBoundaryCoercionAndEquality(): void
    {
        $service = NexusService::from('checkout');

        self::assertTrue($service->equals(NexusService::from($service)));
        self::assertSame('checkout', (string) $service);
        self::assertNull(NexusService::fromNullable(null));
        self::assertNull(NexusOperationName::fromNullable(''));
    }
}
