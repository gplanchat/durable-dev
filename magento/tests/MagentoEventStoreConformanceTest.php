<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Testing\EventStoreReplayConformanceTestCase;
use Gplanchat\DurableModule\Store\MagentoEventStore;

/**
 * The shared event store cases, port and replay tiers, on the Magento adapter (#748). The replay
 * tier extends the port tier, so one subclass runs both. The fence is #749: no fenced passes yet.
 *
 * @see DUR041
 */
final class MagentoEventStoreConformanceTest extends EventStoreReplayConformanceTestCase
{
    protected function createEventStore(): EventStoreInterface
    {
        return new MagentoEventStore(JournalHarness::adapter());
    }
}
