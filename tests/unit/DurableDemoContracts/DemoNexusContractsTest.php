<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Demo\Contracts;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingContract;
use Gplanchat\Durable\Demo\Contracts\Billing\BillingServed;
use Gplanchat\Durable\Demo\Contracts\Stock\StockContract;
use Gplanchat\Durable\Demo\Contracts\Stock\StockServed;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The demonstration contracts are declarations: there is no logic to exercise, but there are three
 * ways to write them wrong, and all three fail in silence.
 */
final class DemoNexusContractsTest extends TestCase
{
    /**
     * The failure mode: a service name that diverges between the served interface and the one the
     * caller reads. The handler is registered under one name, the caller addresses another, and the
     * task leaves for a service nobody serves. Nothing throws, nothing is traced.
     */
    public function testTheTwoHalvesOfAContractNameTheSameService(): void
    {
        $resolver = new NexusContractResolver();

        self::assertSame('stock', $resolver->serviceName(StockServed::class));
        self::assertSame('stock', $resolver->serviceName(StockContract::class));
        self::assertSame('billing', $resolver->serviceName(BillingServed::class));
        self::assertSame('billing', $resolver->serviceName(BillingContract::class));
    }

    /**
     * The failure mode: an operation declared on the served interface and invisible from the
     * caller's contract. Declared, served, and unreachable. That is why the resolver walks the
     * parent interfaces, and it is what this assertion holds.
     */
    public function testTheCallerSeesTheInheritedOperationsToo(): void
    {
        $resolver = new NexusContractResolver();

        self::assertSame(['reserve' => 'reserve'], $resolver->operations(StockContract::class));

        // Sorted: the order is the one reflection returns (own methods before inherited ones),
        // and it is not something the routing reads.
        $billing = $resolver->operations(BillingContract::class);
        ksort($billing);
        self::assertSame(['charge' => 'charge', 'verify' => 'verify'], $billing);
    }

    /** @return iterable<string, array{class-string}> */
    public static function contracts(): iterable
    {
        yield 'stock' => [StockContract::class];
        yield 'billing' => [BillingContract::class];
    }

    /**
     * The failure mode: an object as a parameter or as a return. A Nexus payload is plain JSON,
     * decoded into an associative array on the other side of the boundary: a parameter typed
     * `Order` would receive an array, and that is a `TypeError` at the moment the handler is
     * called, not when the contract is written. The constraint is also what lets a handler written
     * in Go or in TypeScript read the same fields.
     */
    #[DataProvider('contracts')]
    public function testEveryOperationTravelsAsPlainJson(string $contract): void
    {
        $portables = ['bool', 'int', 'float', 'string', 'array'];

        foreach ((new \ReflectionClass($contract))->getMethods() as $method) {
            if ([] === $method->getAttributes(AsNexusOperation::class)) {
                continue;
            }

            $types = [$method->getReturnType()];
            foreach ($method->getParameters() as $parameter) {
                $types[] = $parameter->getType();
            }

            foreach ($types as $type) {
                self::assertInstanceOf(\ReflectionNamedType::class, $type, $method->getName() . '(): every type is declared');
                self::assertContains($type->getName(), $portables, \sprintf(
                    '%s::%s() uses %s, which does not survive the JSON round trip.',
                    $contract,
                    $method->getName(),
                    $type->getName(),
                ));
            }
        }
    }
}
