<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\EventStoreReplayConformanceTestCase;

/**
 * The reference replays the suite. Without this file, DUR041 would compare the adapters against a
 * definition nothing checks.
 *
 * It joins the replay tier too (#1011). That tier compares a store with an `InMemoryEventStore`,
 * and here both sides are one: the reference of this class is a second in-memory run of the same
 * workflow in a distinct execution. What the cases assert on this backend is that the workflow and
 * the history lookups are deterministic and that the scrubbed journal has the same shape twice.
 * They do not compare InMemory with another backend; DBAL, Illuminate and Temporal do that against
 * this one. No case of the tier is excluded here.
 *
 * @see DUR041
 */
final class InMemoryEventStoreConformanceTest extends EventStoreReplayConformanceTestCase
{
    protected function createEventStore(): EventStoreInterface
    {
        return new InMemoryEventStore();
    }

    protected function expectsFencedPasses(): bool
    {
        return true;
    }
}
