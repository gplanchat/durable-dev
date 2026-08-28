<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\Deferred;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationSchedulerInterface;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\NexusStub;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;
use PHPUnit\Framework\TestCase;

final class NexusStubTest extends TestCase
{
    public function testAMethodCallSchedulesItsOperation(): void
    {
        $scheduler = new RecordingNexusScheduler();
        $stub = new NexusStub(
            $scheduler,
            FacturationContract::class,
            new NexusContractResolver(),
            NexusEndpoint::named('paiements'),
        );

        $stub->encaisser('cmd-1', 1200);

        self::assertSame('paiements', $scheduler->endpoint?->name());
        self::assertSame('facturation', $scheduler->service?->name());
        self::assertSame('encaisser', $scheduler->operation?->name());
    }

    public function testNamedArgumentsBecomeTheCallersPayload(): void
    {
        // La charge voyage telle que l'appelant l'a écrite — pas d'enveloppe (1b.2). Les noms des
        // clés sont ceux des paramètres du contrat, donc un gestionnaire d'un autre SDK y trouve
        // les champs qu'il déclare.
        $scheduler = new RecordingNexusScheduler();
        $stub = new NexusStub(
            $scheduler,
            FacturationContract::class,
            new NexusContractResolver(),
            NexusEndpoint::named('paiements'),
        );

        $stub->encaisser('cmd-1', 1200);

        self::assertSame(['ordre' => 'cmd-1', 'montant' => 1200], $scheduler->payload);
    }

    public function testAnInheritedOperationIsCallable(): void
    {
        // Le contrat de l'appelant étend celui que le gestionnaire implémente : sans les méthodes
        // héritées, l'appelant ne saurait pas appeler ce que le gestionnaire sert.
        $scheduler = new RecordingNexusScheduler();
        $stub = new NexusStub(
            $scheduler,
            FacturationContract::class,
            new NexusContractResolver(),
            NexusEndpoint::named('paiements'),
        );

        $stub->verifier('cmd-1');

        self::assertSame('verifier', $scheduler->operation?->name());
    }

    public function testAMethodThatIsNotAnOperationIsRefused(): void
    {
        $stub = new NexusStub(
            new RecordingNexusScheduler(),
            FacturationContract::class,
            new NexusContractResolver(),
            NexusEndpoint::named('paiements'),
        );

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/inexistante/');

        $stub->inexistante();
    }
}

#[AsNexusService('facturation')]
interface FacturationServed
{
    #[AsNexusOperation('verifier')]
    public function verifier(string $ordre): string;
}

#[AsNexusService('facturation')]
interface FacturationContract extends FacturationServed
{
    #[AsNexusOperation('encaisser')]
    public function encaisser(string $ordre, int $montant): string;
}

final class RecordingNexusScheduler implements NexusOperationSchedulerInterface
{
    public ?NexusEndpoint $endpoint = null;
    public ?NexusService $service = null;
    public ?NexusOperationName $operation = null;
    /** @var array<string, mixed>|null */
    public ?array $payload = null;

    public function scheduleNexusOperation(
        NexusEndpoint $endpoint,
        NexusService $service,
        NexusOperationName $operation,
        array $payload,
        ?NexusOperationTimeouts $timeouts,
    ): Awaitable {
        $this->endpoint = $endpoint;
        $this->service = $service;
        $this->operation = $operation;
        $this->payload = $payload;

        return Deferred::resolved(null);
    }
}
