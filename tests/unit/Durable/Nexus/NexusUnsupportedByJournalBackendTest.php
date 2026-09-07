<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use PHPUnit\Framework\TestCase;

/**
 * Nexus is cross-namespace by nature: calling an operation served by another team has no
 * equivalent in a local journal. The proposal writes it down — the journal backend **refuses** the
 * call with an explicit error rather than pretending.
 *
 * This refusal is not a gap to fill in later: it is the intended behaviour. A backend that
 * accepted the command and did nothing with it would leave the workflow waiting for a result
 * nobody will ever produce — the silent failure, again.
 *
 * @see openspec/changes/temporal-nexus-support/proposal.md
 * @see openspec/changes/temporal-nexus-support/tasks.md §3.4
 */
final class NexusUnsupportedByJournalBackendTest extends TestCase
{
    public function testSchedulingIsRefusedWithAnExplicitError(): void
    {
        $this->expectException(NexusUnsupportedByBackendException::class);
        $this->expectExceptionMessage('Nexus');

        $this->buffer()->scheduleNexusOperation(
            'op-1',
            NexusEndpoint::named('billing-endpoint'),
            NexusService::named('billing'),
            NexusOperationName::named('charge'),
            ['amount' => 10],
            NexusOperationTimeouts::none(),
            NexusOperationHeaders::none(),
        );
    }

    public function testCancellingIsRefusedTheSameWay(): void
    {
        $this->expectException(NexusUnsupportedByBackendException::class);

        $this->buffer()->cancelNexusOperation('op-1', 'workflow cancelled');
    }

    public function testTheErrorNamesTheBackendAndPointsAtTemporal(): void
    {
        // The message must say what to do, not only that it is impossible: the reader is a
        // developer who has just written a Nexus call and does not yet know that their backend
        // cannot serve it.
        try {
            $this->buffer()->cancelNexusOperation('op-1', 'peu importe');
            self::fail('The journal backend accepted a Nexus operation.');
        } catch (NexusUnsupportedByBackendException $e) {
            self::assertStringContainsString('Temporal', $e->getMessage());
        }
    }

    private function buffer(): EventStoreCommandBuffer
    {
        return new EventStoreCommandBuffer(
            new InMemoryEventStore(),
            new NoopActivityTransport(),
            'exec-1',
        );
    }
}
