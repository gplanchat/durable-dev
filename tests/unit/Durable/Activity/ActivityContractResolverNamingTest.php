<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Activity;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use PHPUnit\Framework\TestCase;

#[AsActivity(name: '')]
interface UnnamedContract
{
    #[AsActivityMethod(name: 'Ordercharge')]
    public function charge(string $orderId): string;
}

#[AsActivity(name: 'Billing.Order')]
interface DottedContract
{
    #[AsActivityMethod(name: 'charge')]
    public function charge(string $orderId): string;
}

/**
 * The durable-rector README states this rule when it explains which SDK prefixes carry over: the
 * dot goes in only when the contract name is not empty.
 */
final class ActivityContractResolverNamingTest extends TestCase
{
    public function testAnEmptyContractNameLeavesTheMethodNameAlone(): void
    {
        self::assertSame(
            ['charge' => 'Ordercharge'],
            (new ActivityContractResolver())->resolveActivityMethods(UnnamedContract::class),
        );
    }

    public function testAContractNameIsJoinedWithOneDot(): void
    {
        self::assertSame(
            ['charge' => 'Billing.Order.charge'],
            (new ActivityContractResolver())->resolveActivityMethods(DottedContract::class),
        );
    }
}
