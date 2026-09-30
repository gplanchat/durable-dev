<?php

namespace unit\DurableRector\Fixture\ExecutionId;

use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;

// An implementation of a #682 port is migrated by hand (UPGRADE), and so are its calls to the #638
// ports: once its parameter becomes ExecutionId, a wrap written here would be fromString(ExecutionId).
final class CountingTimerDispatcher implements WorkflowTimerDispatcher
{
    public function __construct(
        private WorkflowTimerDispatcher $inner,
        private EventStoreInterface $events,
    ) {}

    public function dispatchTimerFire(string $executionId, int $delayMs = 0): void
    {
        $this->events->countEventsInStream($executionId);
        $this->inner->dispatchTimerFire($executionId, $delayMs);
    }
}
