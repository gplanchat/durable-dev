<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

final class AsksWhenTheNextTimerIsDue
{
    public function due(object $store, string $executionId, float $now): ?int
    {
        return \Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator::millisecondsUntilNextTimerDue($store, $executionId, $now);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

final class AsksWhenTheNextTimerIsDue
{
    public function due(object $store, string $executionId, float $now): ?int
    {
        return \Gplanchat\Durable\Timer\TimerWakeDelayCalculator::millisecondsUntilNextTimerDue($store, $executionId, $now);
    }
}

?>
