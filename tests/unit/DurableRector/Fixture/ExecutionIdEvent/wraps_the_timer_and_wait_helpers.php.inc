<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Observation\WaitReason;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Timer\PendingTimers;
use Gplanchat\Durable\Timer\TimerWakeDelayCalculator;

final class ReadsTheTimers
{
    public function __invoke(EventStoreInterface $store, Awaitable $pending, string $executionId): array
    {
        return [
            PendingTimers::of($store, $executionId),
            PendingTimers::dueAt($store, 'exec-1', 10.0),
            TimerWakeDelayCalculator::millisecondsUntilNextTimerDue($store, $executionId, 10.0),
            WaitReason::describe($pending, $store, $executionId),
        ];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Observation\WaitReason;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Timer\PendingTimers;
use Gplanchat\Durable\Timer\TimerWakeDelayCalculator;

final class ReadsTheTimers
{
    public function __invoke(EventStoreInterface $store, Awaitable $pending, string $executionId): array
    {
        return [
            PendingTimers::of($store, \Gplanchat\Durable\ExecutionId::fromString($executionId)),
            PendingTimers::dueAt($store, \Gplanchat\Durable\ExecutionId::fromString('exec-1'), 10.0),
            TimerWakeDelayCalculator::millisecondsUntilNextTimerDue($store, \Gplanchat\Durable\ExecutionId::fromString($executionId), 10.0),
            WaitReason::describe($pending, $store, \Gplanchat\Durable\ExecutionId::fromString($executionId)),
        ];
    }
}

?>
