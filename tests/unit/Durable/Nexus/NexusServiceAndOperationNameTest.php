<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les verdicts de la sonde §1.1 (moitié service/opération), rendus impossibles à subir.
 *
 * Le serveur n'en valide aucun : vide, blancs, tabulation, caractère de contrôle, mille
 * caractères — tout est accepté et enregistré verbatim, puis l'opération attend un gestionnaire
 * qui ne correspondra jamais, sans une ligne d'erreur. C'est la panne muette de
 * {@see \Gplanchat\Durable\TaskQueue}, et elle appelle le même remède : être plus strict que le
 * serveur sur ce qui ne peut être qu'une faute.
 *
 * À l'inverse de {@see \Gplanchat\Durable\Nexus\NexusEndpoint}, que le serveur refuse net et qui
 * n'a donc rien à inventer.
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
        // Le serveur n'impose aucun alphabet : tout ce qui n'est pas une faute évidente passe.
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
        // §1.4 : ne pas écrire d'invariant qui n'a pas été observé. Mille caractères ont été
        // acceptés par le serveur et aucune borne haute n'a été trouvée — on n'en fabrique pas.
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
