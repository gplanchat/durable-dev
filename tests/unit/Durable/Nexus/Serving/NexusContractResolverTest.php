<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;
use PHPUnit\Framework\TestCase;

final class NexusContractResolverTest extends TestCase
{
    public function testTheServiceNameComesFromTheContract(): void
    {
        self::assertSame('billing', (new NexusContractResolver())->serviceName(ServedContract::class));
    }

    public function testAnnotatedMethodsBecomeOperations(): void
    {
        $operations = (new NexusContractResolver())->operations(ServedContract::class);

        self::assertSame(['verify' => 'verify'], $operations);
    }

    public function testAnInheritedOperationIsPartOfTheExtendingContract(): void
    {
        // The difference that matters with the activity resolver, which ignores inheritance.
        // Here the full contract **extends** the served contract: that is what lets the handler
        // implement only the immediate part without writing an empty method. Skipping the
        // inherited methods would make `verify` disappear from the caller's view — an operation
        // declared, served, and that the stub would not know how to call.
        $operations = (new NexusContractResolver())->operations(FullContract::class);

        self::assertSame(
            ['collect' => 'collect', 'verify' => 'verify'],
            $operations,
        );
    }

    public function testAMethodWithoutTheAttributeIsNotAnOperation(): void
    {
        self::assertArrayNotHasKey('internal', (new NexusContractResolver())->operations(ContractWithABareMethod::class));
    }

    public function testAContractWithoutAServiceNameIsRefused(): void
    {
        // Unlike `#[AsActivity]`, which is optional and falls back on the method name. Here the
        // service name **addresses** a task: falling back on the interface's short name would
        // produce a name the caller's endpoint would not recognize, and an operation waiting for a
        // handler whose name will never match.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/AsNexusService/');

        (new NexusContractResolver())->serviceName(UnnamedContract::class);
    }

    public function testTwoOperationsSharingAnameAreRefused(): void
    {
        // Routing happens by (service, operation): two methods with the same operation name
        // would make the switching arbitrary, and the loser would never be called.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/collect/');

        (new NexusContractResolver())->operations(AmbiguousContract::class);
    }
}

#[AsNexusService('billing')]
interface ServedContract
{
    #[AsNexusOperation('verify')]
    public function verify(string $order): string;
}

#[AsNexusService('billing')]
interface FullContract extends ServedContract
{
    #[AsNexusOperation('collect')]
    public function collect(string $order): string;
}

#[AsNexusService('billing')]
interface ContractWithABareMethod
{
    #[AsNexusOperation('verify')]
    public function verify(string $order): string;

    public function internal(): void;
}

interface UnnamedContract
{
    #[AsNexusOperation('verify')]
    public function verify(string $order): string;
}

#[AsNexusService('billing')]
interface AmbiguousContract
{
    #[AsNexusOperation('collect')]
    public function collectByCard(string $order): string;

    #[AsNexusOperation('collect')]
    public function collectByTransfer(string $order): string;
}
