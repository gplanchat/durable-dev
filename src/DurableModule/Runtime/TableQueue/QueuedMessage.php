<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime\TableQueue;

/**
 * A message taken from the queue: acknowledge it with {@see TableQueue::ack()} once handled, or
 * its lease runs out and it is delivered again.
 */
final readonly class QueuedMessage
{
    public function __construct(public int $id, public string $body) {}
}
