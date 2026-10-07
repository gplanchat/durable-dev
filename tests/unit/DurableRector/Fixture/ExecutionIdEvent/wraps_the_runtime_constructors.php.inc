<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Bridge\Temporal\Store\TemporalEventConverter;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityTransportInterface;

final class BuildsAPass
{
    public function __invoke(string $executionId, EventStoreInterface $store, ActivityTransportInterface $transport, WorkflowHistorySourceInterface $history, WorkflowCommandBufferInterface $buffer, TemporalConnection $connection): array
    {
        return [
            new ExecutionContext($executionId, $history, $buffer),
            new EventStoreCommandBuffer($store, $transport, 'exec-1'),
            new TemporalEventConverter($executionId),
            TemporalEventConverter::forHistory($executionId, []),
            new TemporalWorkflowCommandBuffer($connection, $executionId),
        ];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Bridge\Temporal\Store\TemporalEventConverter;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityTransportInterface;

final class BuildsAPass
{
    public function __invoke(string $executionId, EventStoreInterface $store, ActivityTransportInterface $transport, WorkflowHistorySourceInterface $history, WorkflowCommandBufferInterface $buffer, TemporalConnection $connection): array
    {
        return [
            new ExecutionContext(\Gplanchat\Durable\ExecutionId::fromString($executionId), $history, $buffer),
            new EventStoreCommandBuffer($store, $transport, \Gplanchat\Durable\ExecutionId::fromString('exec-1')),
            new TemporalEventConverter(\Gplanchat\Durable\ExecutionId::fromString($executionId)),
            TemporalEventConverter::forHistory(\Gplanchat\Durable\ExecutionId::fromString($executionId), []),
            new TemporalWorkflowCommandBuffer($connection, \Gplanchat\Durable\ExecutionId::fromString($executionId)),
        ];
    }
}

?>
