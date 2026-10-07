<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\PassEventStore;

final class OpensAPass
{
    public function __invoke(EventStoreInterface $store, string $executionId): array
    {
        return [
            new EventStoreHistorySource($store, $executionId),
            PassEventStore::open($store, 'exec-1'),
        ];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\PassEventStore;

final class OpensAPass
{
    public function __invoke(EventStoreInterface $store, string $executionId): array
    {
        return [
            new EventStoreHistorySource($store, \Gplanchat\Durable\ExecutionId::fromString($executionId)),
            PassEventStore::open($store, \Gplanchat\Durable\ExecutionId::fromString('exec-1')),
        ];
    }
}

?>
