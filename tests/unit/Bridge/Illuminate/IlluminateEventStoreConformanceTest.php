<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Testing\EventStoreReplayConformanceTestCase;
use unit\Bridge\SqlTestDatabase;

/**
 * `illuminate/database` is usable with no Laravel application around it — that is what Capsule is,
 * and the adapters touch nothing but a `Connection`. No container, no service provider:
 * the surface is the one a real application would hand them.
 *
 * The replay tier is joined: this journal can drive a live workflow, so it differentiates itself
 * against the in-memory reference rather than settling for the contract.
 *
 * @see DUR041
 */
final class IlluminateEventStoreConformanceTest extends EventStoreReplayConformanceTestCase
{
    protected function createEventStore(): EventStoreInterface
    {
        $connection = SqlTestDatabase::illuminate();

        return new IlluminateEventStore($connection, new DurableSchema($connection));
    }
}
