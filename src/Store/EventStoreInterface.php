<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;

/**
 * Port de persistance des événements de workflow (event sourcing).
 *
 * @see ADR004 Ports et Adapters
 */
interface EventStoreInterface
{
    public function append(Event $event): void;

    /**
     * @return iterable<Event>
     */
    public function readStream(string $executionId): iterable;
}
