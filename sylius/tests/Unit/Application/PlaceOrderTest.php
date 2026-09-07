<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\Port\Payments;
use App\Application\UseCase\PlaceOrder;
use App\Domain\Payment\Authorisation;
use App\Domain\Payment\Money;
use App\Domain\Payment\OrderId;
use App\Domain\Payment\Receipt;
use PHPUnit\Framework\TestCase;

/**
 * The use case, exercised without a cluster.
 *
 * That is the whole claim of the arrangement: `PlaceOrder` cannot tell whether the money moved next
 * door or across a Nexus endpoint, so a fake implementing the port is enough to pin its one rule.
 */
final class PlaceOrderTest extends TestCase
{
    public function testARefusedOrderIsNeverCharged(): void
    {
        $payments = new RecordingPayments(Authorisation::fromWire(['accepted' => false, 'reason' => 'no funds']));

        $outcome = (new PlaceOrder($payments))(OrderId::fromString('ORD-42'), Money::of(1200, 'EUR'));

        self::assertSame(['authorise'], $payments->calls);
        self::assertFalse($outcome->isPaid());
        self::assertSame(
            ['verified' => ['accepted' => false, 'reason' => 'no funds'], 'charge' => null],
            $outcome->toWire(),
        );
    }

    public function testAnAcceptedOrderIsAskedBeforeItIsCharged(): void
    {
        $payments = new RecordingPayments(Authorisation::granted());

        $outcome = (new PlaceOrder($payments))(OrderId::fromString('ORD-42'), Money::of(1200, 'EUR'));

        self::assertSame(['authorise', 'capture'], $payments->calls);
        self::assertTrue($outcome->isPaid());
        self::assertSame(
            ['verified' => ['accepted' => true, 'reason' => null], 'charge' => ['receipt' => 'RCP-1', 'charged' => 1200]],
            $outcome->toWire(),
        );
    }

    public function testTheShopHandsBillingWhatItAskedWith(): void
    {
        $payments = new RecordingPayments(Authorisation::granted());

        (new PlaceOrder($payments))(OrderId::fromString('ORD-42'), Money::of(999, 'USD'));

        self::assertSame('ORD-42', $payments->lastOrder?->toString());
        self::assertSame(999, $payments->lastAmount?->cents());
        self::assertSame('USD', $payments->lastAmount?->currency()->code());
    }
}

final class RecordingPayments implements Payments
{
    /** @var list<string> */
    public array $calls = [];

    public ?OrderId $lastOrder = null;

    public ?Money $lastAmount = null;

    public function __construct(
        private readonly Authorisation $verdict,
    ) {}

    public function authorise(OrderId $order, Money $amount): Authorisation
    {
        $this->calls[] = 'authorise';
        $this->lastOrder = $order;
        $this->lastAmount = $amount;

        return $this->verdict;
    }

    public function capture(OrderId $order, Money $amount): Receipt
    {
        $this->calls[] = 'capture';
        $this->lastOrder = $order;
        $this->lastAmount = $amount;

        return Receipt::fromWire(['receipt' => 'RCP-1', 'charged' => $amount->cents()]);
    }
}
