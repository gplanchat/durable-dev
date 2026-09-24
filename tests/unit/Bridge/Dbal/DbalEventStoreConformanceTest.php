<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Testing\EventStoreReplayConformanceTestCase;
use unit\Bridge\SqlTestDatabase;

/**
 * @see DUR041
 * @see DUR030
 */
final class DbalEventStoreConformanceTest extends EventStoreReplayConformanceTestCase
{
    protected function createEventStore(): EventStoreInterface
    {
        $connection = SqlTestDatabase::dbal();

        return new DbalEventStore($connection, new DurableSchema($connection));
    }
}
