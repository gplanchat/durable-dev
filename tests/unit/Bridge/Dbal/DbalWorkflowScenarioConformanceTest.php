<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Dbal;

use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Testing\WorkflowScenarioConformanceTestCase;
use unit\Bridge\SqlTestDatabase;

/**
 * @see DUR041
 */
final class DbalWorkflowScenarioConformanceTest extends WorkflowScenarioConformanceTestCase
{
    protected function createEventStore(): EventStoreInterface
    {
        $connection = SqlTestDatabase::dbal();

        return new DbalEventStore($connection, new DurableSchema($connection));
    }
}
