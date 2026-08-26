<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Awaitable\NexusOperationAwaitable;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Planifier une opération Nexus depuis le workflow — §3.2 et §3.3 du change temporal-nexus-support.
 *
 * L'opération se comporte comme une activité vis-à-vis du replay : un rang, une identité relue de
 * l'historique, et rien de replanifié quand l'issue y est déjà. Ce qui l'en distingue est ailleurs
 * — elle est servie par un endpoint extérieur, qu'un backend sans route ne peut pas joindre.
 */
final class NexusOperationSchedulingTest extends TestCase
{
    public function testTheJournalBackendRefusesRatherThanHangs(): void
    {
        // Un backend sans route vers l'endpoint doit le dire tout de suite. Attendre une réponse
        // que personne ne produira est le mode d'échec le plus coûteux à diagnostiquer.
        $store = new InMemoryEventStore();
        $context = $this->context(
            new EventStoreHistorySource($store, 'nexus-1'),
            new EventStoreCommandBuffer($store, new InMemoryActivityTransport(), 'nexus-1', static fn(): float => 0.0),
        );

        $this->expectException(NexusUnsupportedByBackendException::class);
        $this->expectExceptionMessageMatches('/journal/');

        $context->nexusOperation(
            NexusEndpoint::named('payments'),
            NexusService::named('billing.v1.Billing'),
            NexusOperationName::named('charge'),
            ['order' => 'o-1'],
            NexusOperationTimeouts::none(),
        );
    }

    public function testARecordedOutcomeIsReplayedWithoutRescheduling(): void
    {
        // Le tampon n'a rien à faire : l'issue est déjà au journal.
        $buffer = $this->createMock(WorkflowCommandBufferInterface::class);
        $buffer->expects(self::never())->method('scheduleNexusOperation');
        $context = $this->context(
            $this->historyWith([0 => ['id' => 'op-0', 'result' => ['charged' => true], 'failed' => null]]),
            $buffer,
        );

        $awaitable = $context->nexusOperation(
            NexusEndpoint::named('payments'),
            NexusService::named('billing.v1.Billing'),
            NexusOperationName::named('charge'),
            [],
            NexusOperationTimeouts::none(),
        );

        self::assertInstanceOf(NexusOperationAwaitable::class, $awaitable);
        self::assertSame('op-0', $awaitable->operationId(), 'l’identité vient de l’historique, pas d’un compteur local');
        self::assertTrue($awaitable->isSettled());
        self::assertSame(['charged' => true], $awaitable->getResult());
    }

    public function testARecordedFailureIsRaisedAgain(): void
    {
        $context = $this->context(
            $this->historyWith([0 => ['id' => 'op-0', 'result' => null, 'failed' => new \DomainException('endpoint refused')]]),
            $this->createMock(WorkflowCommandBufferInterface::class),
        );

        $awaitable = $context->nexusOperation(
            NexusEndpoint::named('payments'),
            NexusService::named('billing.v1.Billing'),
            NexusOperationName::named('charge'),
            [],
            NexusOperationTimeouts::none(),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('endpoint refused');
        $awaitable->getResult();
    }

    public function testEachCallConsumesItsOwnSlot(): void
    {
        // Deux appels, deux rangs : c'est ce qui rend l'identité stable d'une passe à l'autre.
        $context = $this->context(
            $this->historyWith([
                0 => ['id' => 'op-0', 'result' => 'premier', 'failed' => null],
                1 => ['id' => 'op-1', 'result' => 'second', 'failed' => null],
            ]),
            $this->createMock(WorkflowCommandBufferInterface::class),
        );

        $results = [];
        foreach ([0, 1] as $_) {
            $results[] = $context->nexusOperation(
                NexusEndpoint::named('payments'),
                NexusService::named('billing.v1.Billing'),
                NexusOperationName::named('charge'),
                [],
                NexusOperationTimeouts::none(),
            )->getResult();
        }

        self::assertSame(['premier', 'second'], $results);
    }

    public function testAnOperationAlreadyScheduledIsHeldPendingWithoutRescheduling(): void
    {
        // L'identité est au journal mais pas encore l'issue : l'opération est en vol. Rien n'est
        // replanifié, et l'attente est retenue pour que le backend puisse la régler plus tard.
        $buffer = $this->createMock(WorkflowCommandBufferInterface::class);
        $buffer->expects(self::never())->method('scheduleNexusOperation');

        $history = $this->createStub(WorkflowHistorySourceInterface::class);
        $history->method('findScheduledNexusOperation')->willReturn('op-en-vol');
        $history->method('findNexusOperationSlotResult')->willReturn(null);

        $context = $this->context($history, $buffer);
        $awaitable = $context->nexusOperation(
            NexusEndpoint::named('payments'),
            NexusService::named('billing.v1.Billing'),
            NexusOperationName::named('charge'),
        );

        self::assertFalse($awaitable->isSettled());
        self::assertSame(['op-en-vol'], array_keys($context->pendingNexusOperations()));
    }

    public function testTheEnvironmentExposesTheSameSchedulingAsTheContext(): void
    {
        // La façade est la seule API workflow : ce qui n'y est pas n'existe pas pour l'applicatif.
        $store = new InMemoryEventStore();
        $context = $this->context(
            new EventStoreHistorySource($store, 'nexus-1'),
            new EventStoreCommandBuffer($store, new InMemoryActivityTransport(), 'nexus-1', static fn(): float => 0.0),
        );
        $environment = new WorkflowEnvironment($context, new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor()));

        $this->expectException(NexusUnsupportedByBackendException::class);

        $environment->scheduleNexusOperation(
            NexusEndpoint::named('payments'),
            NexusService::named('billing.v1.Billing'),
            NexusOperationName::named('charge'),
        );
    }

    // -------------------------------------------------------------------------

    private function context(WorkflowHistorySourceInterface $history, WorkflowCommandBufferInterface $buffer): ExecutionContext
    {
        return new ExecutionContext('nexus-1', $history, $buffer);
    }

    /**
     * Historique doté d'issues Nexus déjà enregistrées, et vide de tout le reste.
     *
     * @param array<int, array{id: string, result: mixed, failed: \Throwable|null}> $slots
     */
    private function historyWith(array $slots): WorkflowHistorySourceInterface
    {
        $history = $this->createStub(WorkflowHistorySourceInterface::class);
        $history->method('findNexusOperationSlotResult')->willReturnCallback(
            static fn(int $slot): ?array => isset($slots[$slot])
                ? ['result' => $slots[$slot]['result'], 'failed' => $slots[$slot]['failed']]
                : null,
        );
        $history->method('findScheduledNexusOperation')->willReturnCallback(
            static fn(int $slot): ?string => $slots[$slot]['id'] ?? null,
        );

        return $history;
    }
}
