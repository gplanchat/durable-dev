<?php

namespace unit\DurableRector\Fixture\ExecutionId;

use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;

final class WordsAWait
{
    public function __invoke(TemporalExecutionHistory $history, string $executionId): array
    {
        return $history->waitJournal($executionId);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionId;

use Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory;

final class WordsAWait
{
    public function __invoke(TemporalExecutionHistory $history, string $executionId): array
    {
        return $history->waitJournal(\Gplanchat\Durable\ExecutionId::fromString($executionId));
    }
}

?>
