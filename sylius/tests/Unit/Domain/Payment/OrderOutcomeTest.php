<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Payment;

use App\Domain\Payment\Authorisation;
use App\Domain\Payment\Cents;
use App\Domain\Payment\Receipt;
use App\Domain\Payment\ReceiptNumber;
use App\Domain\Payment\OrderOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The outbound half, and a characterisation of what the demonstration prints.
 *
 * `OrderWorkflow` returned `['verified' => …, 'charge' => …]` before any of this existed, and
 * `durable:demo:bill` prints it. Moving the assembly behind a type is only safe if the shape does
 * not move with it, so the shape is pinned here.
 */
final class OrderOutcomeTest extends TestCase
{
    public function testARefusedOrderKeepsTheShapeTheDemonstrationPrints(): void
    {
        $outcome = OrderOutcome::refused(Authorisation::fromWire(['accepted' => false, 'reason' => 'no']));

        self::assertFalse($outcome->isPaid());
        self::assertSame(
            ['verified' => ['accepted' => false, 'reason' => 'no'], 'charge' => null],
            $outcome->toWire(),
        );
    }

    public function testAPaidOrderCarriesTheReceiptInTheSameShape(): void
    {
        $outcome = OrderOutcome::paid(
            Authorisation::granted(),
            Receipt::fromWire(['receipt' => 'RCP-7', 'charged' => 1200]),
        );

        self::assertTrue($outcome->isPaid());
        self::assertSame(
            ['verified' => ['accepted' => true, 'reason' => null], 'charge' => ['receipt' => 'RCP-7', 'charged' => 1200]],
            $outcome->toWire(),
        );
    }

    public function testAReceiptRefusesAPayloadItCannotRead(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        Receipt::fromWire(['recu' => 'RCP-7', 'encaisse' => 1200]);
    }

    public function testAReceiptWithoutANumberIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ReceiptNumber::fromString(' ');
    }

    public function testTheChargedAmountHasNoCurrency(): void
    {
        // `charge` answers an amount and no currency, so `Receipt` carries `Cents`. If this ever
        // becomes a `Money`, the contract gained a field and this test is where to notice.
        $receipt = Receipt::fromWire(['receipt' => 'RCP-7', 'charged' => 1200]);

        self::assertInstanceOf(Cents::class, $receipt->charged);
    }
}
