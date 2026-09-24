<?php

declare(strict_types=1);

namespace integration\Temporal;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\TemporalJournalEventStore;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Testing\EventStoreConformanceTestCase;

/**
 * The port tier of DUR041 against a real Temporal server: the journal store writes by signal and
 * reads the server history back. No worker is needed, a signal lands in the history all the same.
 *
 * Only the port tier: a server-backed store replays the other tier in the integration suite (see
 * {@see \Gplanchat\Durable\Testing\EventStoreReplayConformanceTestCase}).
 *
 * @see DUR041
 */
final class TemporalJournalEventStoreConformanceTest extends EventStoreConformanceTestCase
{
    use FreshNamespace;

    private TemporalConnection $connection;

    protected function setUp(): void
    {
        $this->connection = self::freshNamespaceConnection();
    }

    protected function createEventStore(): EventStoreInterface
    {
        return new TemporalJournalEventStore(WorkflowServiceClientFactory::create($this->connection), $this->connection);
    }
}
