<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Testing\EventStoreConformanceTestCase;

/**
 * La référence rejoue la suite. Sans ce fichier, DUR041 comparerait les adaptateurs à une
 * définition que rien ne vérifie.
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
