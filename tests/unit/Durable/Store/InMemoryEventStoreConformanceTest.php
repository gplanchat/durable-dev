<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\EventStoreConformanceTestCase;

/**
 * The reference replays the suite. Without this file, DUR041 would compare the adapters against a
 * definition nothing checks.
 *
 * @see DUR041
 */
final class InMemoryEventStoreConformanceTest extends EventStoreConformanceTestCase
{
    protected function createEventStore(): EventStoreInterface
    {
        return new InMemoryEventStore();
    }
}
