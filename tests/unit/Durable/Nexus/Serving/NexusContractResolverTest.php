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
        self::assertSame('facturation', (new NexusContractResolver())->serviceName(ContratServi::class));
    }

    public function testAnnotatedMethodsBecomeOperations(): void
    {
        $operations = (new NexusContractResolver())->operations(ContratServi::class);

        self::assertSame(['verifier' => 'verifier'], $operations);
    }

    public function testAnInheritedOperationIsPartOfTheExtendingContract(): void
    {
        // The difference that matters with the activity resolver, which ignores inheritance.
        // Here the full contract **extends** the served contract: that is what lets the handler
        // implement only the immediate part without writing an empty method. Skipping the
        // inherited methods would make `verifier` disappear from the caller's view — an operation
        // declared, served, and that the stub would not know how to call.
        $operations = (new NexusContractResolver())->operations(ContratComplet::class);

        self::assertSame(
            ['encaisser' => 'encaisser', 'verifier' => 'verifier'],
            $operations,
        );
    }

    public function testAMethodWithoutTheAttributeIsNotAnOperation(): void
    {
        self::assertArrayNotHasKey('interne', (new NexusContractResolver())->operations(ContratAvecMethodeNue::class));
    }

    public function testAContractWithoutAServiceNameIsRefused(): void
    {
        // Unlike `#[AsActivity]`, which is optional and falls back on the method name. Here the
        // service name **addresses** a task: falling back on the interface's short name would
        // produce a name the caller's endpoint would not recognize, and an operation waiting for a
        // handler whose name will never match.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/AsNexusService/');

        (new NexusContractResolver())->serviceName(ContratSansNom::class);
    }

    public function testTwoOperationsSharingAnameAreRefused(): void
    {
        // Routing happens by (service, operation): two methods with the same operation name
        // would make the switching arbitrary, and the loser would never be called.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/encaisser/');

        (new NexusContractResolver())->operations(ContratAmbigu::class);
    }
}

#[AsNexusService('facturation')]
interface ContratServi
{
    #[AsNexusOperation('verifier')]
    public function verifier(string $ordre): string;
}

#[AsNexusService('facturation')]
interface ContratComplet extends ContratServi
{
    #[AsNexusOperation('encaisser')]
    public function encaisser(string $ordre): string;
}

#[AsNexusService('facturation')]
interface ContratAvecMethodeNue
{
    #[AsNexusOperation('verifier')]
    public function verifier(string $ordre): string;

    public function interne(): void;
}

interface ContratSansNom
{
    #[AsNexusOperation('verifier')]
    public function verifier(string $ordre): string;
}

#[AsNexusService('facturation')]
interface ContratAmbigu
{
    #[AsNexusOperation('encaisser')]
    public function encaisserParCarte(string $ordre): string;

    #[AsNexusOperation('encaisser')]
    public function encaisserParVirement(string $ordre): string;
}
