<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Payment;

use App\Domain\Payment\Cents;
use App\Domain\Payment\Currency;
use App\Domain\Payment\Money;
use App\Domain\Payment\OrderId;
use PHPUnit\Framework\TestCase;

/**
 * The four values the shop hands to billing, and the rules the contract leaves to the caller.
 *
 * `verify` and `charge` both declare `string $order, int $amount, string $currency`, and validate
 * none of the three. What the wire accepts and what the shop means are not the same set, and this
 * is where the difference is written down.
 */
final class MoneyTest extends TestCase
{
    public function testAnOrderIdKeepsItsTextAndRefusesToBeEmpty(): void
    {
        self::assertSame('ORD-42', OrderId::fromString('ORD-42')->toString());

        $this->expectException(\InvalidArgumentException::class);
        OrderId::fromString('   ');
    }

    public function testACurrencyIsThreeUppercaseLetters(): void
    {
        self::assertSame('EUR', Currency::fromCode('EUR')->code());

        $this->expectException(\InvalidArgumentException::class);
        Currency::fromCode('eur');
    }

    public function testCentsRefuseToBeNegative(): void
    {
        self::assertSame(1200, Cents::of(1200)->toInt());
        self::assertSame(0, Cents::of(0)->toInt());

        $this->expectException(\InvalidArgumentException::class);
        Cents::of(-1);
    }

    public function testMoneyCarriesBothHalvesToTheWire(): void
    {
        $amount = Money::of(1200, 'EUR');

        self::assertSame(1200, $amount->cents());
        self::assertSame('EUR', $amount->currency()->code());
    }
}
