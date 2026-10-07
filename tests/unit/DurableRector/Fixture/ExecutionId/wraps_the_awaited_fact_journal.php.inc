<?php

namespace unit\DurableRector\Fixture\ExecutionId;

use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\AwaitedFact;

final class ChecksAFact
{
    public function __invoke(EventStoreInterface $journal, string $executionId): bool
    {
        return AwaitedFact::signal('req-1')->isJournalledIn($journal, $executionId);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionId;

use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\AwaitedFact;

final class ChecksAFact
{
    public function __invoke(EventStoreInterface $journal, string $executionId): bool
    {
        return AwaitedFact::signal('req-1')->isJournalledIn($journal, \Gplanchat\Durable\ExecutionId::fromString($executionId));
    }
}

?>
