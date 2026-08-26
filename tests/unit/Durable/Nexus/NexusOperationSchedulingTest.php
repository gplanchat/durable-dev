<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Awaitable\NexusOperationAwaitable;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Planifier une opération Nexus, et la retrouver au replay.
 *
 * Même discipline de slot que les activités : le rang de l'appel dans le workflow identifie
 * l'opération d'une passe à l'autre. Sans lui, un replay replanifierait ce qui est déjà en vol —
 * et Nexus appelle un système tiers, où une opération émise deux fois est un vrai doublon, pas une
 * écriture idempotente qu'on rattrape chez soi.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §3.2
 */
final class NexusOperationSchedulingTest extends TestCase
{
    /** @var list<array{operationId: string, endpoint: string, service: string, operation: string}> */
    private array $scheduled = [];

    /** @var list<int> */
    private array $askedSlots = [];

    public function testAFirstRunSchedulesTheOperationAndWaits(): void
    {
        $awaitable = $this->schedule($this->context($this->history()));

        self::assertFalse($awaitable->isSettled());
        self::assertCount(1, $this->scheduled);
        self::assertSame('billing-endpoint', $this->scheduled[0]['endpoint']);
        self::assertSame('billing', $this->scheduled[0]['service']);
        self::assertSame('charge', $this->scheduled[0]['operation']);
        self::assertNotSame('', $this->scheduled[0]['operationId']);
    }

    public function testEachCallTakesTheNextSlot(): void
    {
        $context = $this->context($this->history());

        $this->schedule($context);
        $this->schedule($context);
        $this->schedule($context);

        self::assertSame([0, 1, 2], $this->askedSlots);
    }

    public function testAReplayedOperationIsNotScheduledTwice(): void
    {
        // Le cœur : l'historique dit qu'elle est déjà partie, on ne la renvoie pas.
        $awaitable = $this->schedule($this->context($this->history(scheduledId: 'op-deja-partie')));

        self::assertCount(0, $this->scheduled, 'Une opération déjà planifiée a été renvoyée au fournisseur.');
        self::assertSame('op-deja-partie', $awaitable->operationId());
        self::assertFalse($awaitable->isSettled());
    }

    public function testARecordedResultSettlesTheAwaitableWithoutScheduling(): void
    {
        $awaitable = $this->schedule($this->context($this->history(
            scheduledId: 'op-finie',
            slotResult: ['result' => ['invoice' => 'INV-1'], 'failed' => null],
        )));

        self::assertCount(0, $this->scheduled);
        self::assertTrue($awaitable->isSettled());
        self::assertSame(['invoice' => 'INV-1'], $awaitable->getResult());
        self::assertSame('op-finie', $awaitable->operationId());
    }

    public function testARecordedFailureRejects(): void
    {
        $awaitable = $this->schedule($this->context($this->history(
            scheduledId: 'op-ratee',
            slotResult: ['result' => null, 'failed' => new \RuntimeException('handler exploded')],
        )));

        self::assertCount(0, $this->scheduled);
        self::assertTrue($awaitable->isSettled());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('handler exploded');
        $awaitable->getResult();
    }

    private function schedule(ExecutionContext $context): NexusOperationAwaitable
    {
        $awaitable = $context->nexusOperation(
            NexusEndpoint::named('billing-endpoint'),
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            ['amount' => 10],
            NexusOperationTimeouts::none(),
        );

        self::assertInstanceOf(NexusOperationAwaitable::class, $awaitable);

        return $awaitable;
    }

    /**
     * @param array{result: mixed, failed: \Throwable|null}|null $slotResult
     */
    private function history(?string $scheduledId = null, ?array $slotResult = null): WorkflowHistorySourceInterface&MockObject
    {
        $history = $this->createMock(WorkflowHistorySourceInterface::class);
        $history->method('findScheduledNexusOperation')
            ->willReturnCallback(function (int $slot) use ($scheduledId): ?string {
                $this->askedSlots[] = $slot;

                return $scheduledId;
            });
        $history->method('findNexusOperationSlotResult')->willReturn($slotResult);

        return $history;
    }

    private function context(WorkflowHistorySourceInterface $history): ExecutionContext
    {
        $buffer = $this->createMock(WorkflowCommandBufferInterface::class);
        $buffer->method('scheduleNexusOperation')->willReturnCallback(
            function (string $operationId, NexusEndpoint $endpoint, NexusService $service, NexusOperationName $operation): void {
                $this->scheduled[] = [
                    'operationId' => $operationId,
                    'endpoint' => (string) $endpoint,
                    'service' => (string) $service,
                    'operation' => (string) $operation,
                ];
            },
        );

        return new ExecutionContext('exec-1', $history, $buffer);
    }
}
