<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime\TableQueue;

/**
 * A message taken from the queue: acknowledge it with {@see TableQueue::ack()} once handled, or
 * its lease runs out and it is delivered again.
 */
final readonly class QueuedMessage
{
    /** @param string $leaseToken the end of this delivery's lease: {@see TableQueue::ack()} deletes the row only while it still carries it */
    public function __construct(public int $id, public string $body, public string $leaseToken) {}
}
