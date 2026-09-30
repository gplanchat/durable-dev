<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Event\WorkflowExecutionFailed;

final class TerminatesAChild
{
    public function __invoke(string $childId, string $parentId): WorkflowExecutionFailed
    {
        return WorkflowExecutionFailed::terminatedByParent($childId, $parentId);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Event\WorkflowExecutionFailed;

final class TerminatesAChild
{
    public function __invoke(string $childId, string $parentId): WorkflowExecutionFailed
    {
        return WorkflowExecutionFailed::terminatedByParent(\Gplanchat\Durable\ExecutionId::fromString($childId), $parentId);
    }
}

?>
