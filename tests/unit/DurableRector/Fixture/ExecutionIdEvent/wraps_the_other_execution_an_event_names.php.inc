<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\ExecutionId;

final class NamesAnotherExecution
{
    public function __invoke(ExecutionId $id, string $childId, ?string $maybe): array
    {
        return [
            new ChildWorkflowScheduled($id, $childId, 'App\\Child', []),
            new ChildWorkflowFailed($id, 'child-1', 'boom'),
            new WorkflowCancellationRequested($id, 'stop', $childId),
            new WorkflowCancellationRequested($id, 'stop', null),
            new WorkflowExecutionCancelled($id, 'stop', $maybe),
            new WorkflowContinuedAsNew($id, 'App\\Next', [], [], 'exec-2'),
            new WorkflowContinuedAsNew($id, 'App\\Next', [], newExecutionId: 'exec-2'),
        ];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\ExecutionId;

final class NamesAnotherExecution
{
    public function __invoke(ExecutionId $id, string $childId, ?string $maybe): array
    {
        return [
            new ChildWorkflowScheduled($id, \Gplanchat\Durable\ExecutionId::fromString($childId), 'App\\Child', []),
            new ChildWorkflowFailed($id, \Gplanchat\Durable\ExecutionId::fromString('child-1'), 'boom'),
            new WorkflowCancellationRequested($id, 'stop', \Gplanchat\Durable\ExecutionId::fromString($childId)),
            new WorkflowCancellationRequested($id, 'stop', null),
            new WorkflowExecutionCancelled($id, 'stop', $maybe),
            new WorkflowContinuedAsNew($id, 'App\\Next', [], [], \Gplanchat\Durable\ExecutionId::fromString('exec-2')),
            new WorkflowContinuedAsNew($id, 'App\\Next', [], newExecutionId: 'exec-2'),
        ];
    }
}

?>
