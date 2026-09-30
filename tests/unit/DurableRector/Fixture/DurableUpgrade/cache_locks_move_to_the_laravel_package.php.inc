<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

final class TakesTheLocks
{
    public function __construct(
        private \Gplanchat\Bridge\Illuminate\Queue\ResumeLock $resume,
        private \Gplanchat\Bridge\Illuminate\Queue\ActivityAttemptLock $attempt,
    ) {
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

final class TakesTheLocks
{
    public function __construct(
        private \Gplanchat\Durable\Laravel\Queue\ResumeLock $resume,
        private \Gplanchat\Durable\Laravel\Queue\ActivityAttemptLock $attempt,
    ) {
    }
}

?>
