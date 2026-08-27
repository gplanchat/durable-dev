<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Doctrine\DBAL\DriverManager;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Testing\EventStoreReplayConformanceTestCase;

/**
 * @see DUR041
 * @see DUR030
 */
final class DbalEventStoreConformanceTest extends EventStoreReplayConformanceTestCase
{
    protected function createEventStore(): EventStoreInterface
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        return new DbalEventStore($connection, new DurableSchema($connection));
    }
}
