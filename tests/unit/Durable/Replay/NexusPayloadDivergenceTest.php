<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Replay;

use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * The payload half of the divergence guard, on a Nexus slot, in the core (#326). Only the Temporal
 * bridge exercised it: the journal backends refuse Nexus, so a history double stands in for one
 * that recorded the operation.
 */
final class NexusPayloadDivergenceTest extends TestCase
{
    public function testTheSameOperationWithAnotherPayloadIsRefused(): void
    {
        $this->expectException(WorkflowTaskFailure::class);
        $this->expectExceptionMessageMatches('/Nexus operation slot 0.*payload changed/');

        $this->context(['order' => 1])->nexusOperation(...$this->call(['order' => 2]));
    }

    public function testAFaithfulReplayIsSettledFromTheHistory(): void
    {
        $awaitable = $this->context(['order' => 1])->nexusOperation(...$this->call(['order' => 1]));

        self::assertTrue($awaitable->isSettled());
        self::assertSame('done', $awaitable->getResult());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{NexusEndpoint, NexusService, NexusOperationName, array<string, mixed>}
     */
    private function call(array $payload): array
    {
        return [NexusEndpoint::named('billing'), NexusService::named('invoices'), NexusOperationName::named('issue'), $payload];
    }

    /**
     * @param array<string, mixed> $recordedPayload
     */
    private function context(array $recordedPayload): ExecutionContext
    {
        $history = $this->createStub(WorkflowHistorySourceInterface::class);
        $history->method('nexusOperationSignatureForSlot')->willReturn('billing/invoices/issue');
        $history->method('nexusOperationPayloadForSlot')->willReturn($recordedPayload);
        $history->method('findScheduledNexusOperation')->willReturn('op-1');
        $history->method('findNexusOperationSlotResult')->willReturn(['result' => 'done', 'failed' => null]);

        return new ExecutionContext('exec-nexus', $history, $this->createStub(WorkflowCommandBufferInterface::class));
    }
}
