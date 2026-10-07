<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\WorkflowScenarioConformanceTestCase;

/**
 * The reference: every other backend plays the scenarios it plays.
 *
 * @see DUR041
 */
final class InMemoryWorkflowScenarioConformanceTest extends WorkflowScenarioConformanceTestCase
{
    protected function createEventStore(): EventStoreInterface
    {
        return new InMemoryEventStore();
    }
}
