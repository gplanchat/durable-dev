<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Event\TimerCompleted;

final class WritesATimer
{
    public function __invoke(string $executionId): TimerCompleted
    {
        return new TimerCompleted($executionId, 'timer-1');
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Event\TimerCompleted;

final class WritesATimer
{
    public function __invoke(string $executionId): TimerCompleted
    {
        return new TimerCompleted(\Gplanchat\Durable\ExecutionId::fromString($executionId), 'timer-1');
    }
}

?>
