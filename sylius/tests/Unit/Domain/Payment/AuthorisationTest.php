<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Payment;

use App\Domain\Payment\Authorisation;
use App\Domain\Payment\RefusalReason;
use PHPUnit\Framework\TestCase;

/**
 * The inbound half of the anti-corruption layer, and the only place that knows the field names.
 */
final class AuthorisationTest extends TestCase
{
    public function testAnAcceptedVerdictIsGranted(): void
    {
        self::assertTrue(Authorisation::fromWire(['accepted' => true, 'reason' => null])->isGranted());
    }

    public function testARefusalCarriesItsReason(): void
    {
        $refused = Authorisation::fromWire(['accepted' => false, 'reason' => 'currency not supported']);

        self::assertFalse($refused->isGranted());
        self::assertSame('currency not supported', $refused->reason()->toWire());
    }

    public function testAMissingFieldIsARefusal(): void
    {
        // The payload is keyed by name at both ends. A field that never arrived reads as absent,
        // and absent must not read as yes.
        self::assertFalse(Authorisation::fromWire([])->isGranted());
        self::assertFalse(Authorisation::fromWire(['accepted' => 'true'])->isGranted());
    }

    public function testARefusalWithNothingToSayHasAName(): void
    {
        $reason = Authorisation::fromWire(['accepted' => false])->reason();

        self::assertFalse($reason->isStated());
        self::assertNull($reason->toWire());
        self::assertFalse(RefusalReason::fromWire('   ')->isStated());
    }

    public function testTheWireSurvivesTheRoundTrip(): void
    {
        $wire = ['accepted' => false, 'reason' => 'insufficient funds'];

        self::assertSame($wire, Authorisation::fromWire($wire)->toWire());
    }
}
