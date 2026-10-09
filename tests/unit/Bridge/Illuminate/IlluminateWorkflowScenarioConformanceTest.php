<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Testing\WorkflowScenarioConformanceTestCase;
use unit\Bridge\SqlTestDatabase;

/**
 * @see DUR041
 */
final class IlluminateWorkflowScenarioConformanceTest extends WorkflowScenarioConformanceTestCase
{
    protected function createEventStore(): EventStoreInterface
    {
        $connection = SqlTestDatabase::illuminate();

        return new IlluminateEventStore($connection, new DurableSchema($connection));
    }
}
