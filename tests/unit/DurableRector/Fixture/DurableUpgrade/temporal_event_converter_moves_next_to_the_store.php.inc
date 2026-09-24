<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

final class ConvertsAHistoryEvent
{
    public function convert(\Temporal\Api\History\V1\HistoryEvent $event): mixed
    {
        return \Gplanchat\Bridge\Temporal\Profiler\TemporalEventConverter::class;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

final class ConvertsAHistoryEvent
{
    public function convert(\Temporal\Api\History\V1\HistoryEvent $event): mixed
    {
        return \Gplanchat\Bridge\Temporal\Store\TemporalEventConverter::class;
    }
}

?>
