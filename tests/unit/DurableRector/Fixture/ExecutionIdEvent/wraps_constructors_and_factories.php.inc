<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Failure\FailureEnvelope;

final class JournalWriter
{
    public function __invoke(string $executionId, \Throwable $e, FailureEnvelope $envelope): array
    {
        return [
            new TimerCompleted($executionId, 'timer-1'),
            new ExecutionCompleted('exec-' . $executionId, null),
            new \Gplanchat\Durable\Event\ActivityCompleted('exec-1', 'a1', 42),
            new ChildWorkflowCompleted($executionId, 'child-1', null),
            WorkflowExecutionFailed::workflowHandlerFailure($executionId, $e),
            WorkflowExecutionFailed::fromStoredPayload('exec-1', []),
            ActivityCatastrophicFailure::fromStoredPayload($executionId, []),
            ActivityTaskFailed::forThrowable($executionId, 'a1', 'charge', 1, $e),
            ActivityFailed::fromEnvelope($executionId, 'a1', $envelope),
            \Gplanchat\Durable\Failure\WorkflowFailureClassifier::classify($executionId, $e),
            \Gplanchat\Durable\Failure\ActivityFailureEventFactory::fromActivityThrowable($executionId, 'a1', 'charge', 1, $e),
        ];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Failure\FailureEnvelope;

final class JournalWriter
{
    public function __invoke(string $executionId, \Throwable $e, FailureEnvelope $envelope): array
    {
        return [
            new TimerCompleted(\Gplanchat\Durable\ExecutionId::fromString($executionId), 'timer-1'),
            new ExecutionCompleted(\Gplanchat\Durable\ExecutionId::fromString('exec-' . $executionId), null),
            new \Gplanchat\Durable\Event\ActivityCompleted(\Gplanchat\Durable\ExecutionId::fromString('exec-1'), 'a1', 42),
            new ChildWorkflowCompleted(\Gplanchat\Durable\ExecutionId::fromString($executionId), \Gplanchat\Durable\ExecutionId::fromString('child-1'), null),
            WorkflowExecutionFailed::workflowHandlerFailure(\Gplanchat\Durable\ExecutionId::fromString($executionId), $e),
            WorkflowExecutionFailed::fromStoredPayload(\Gplanchat\Durable\ExecutionId::fromString('exec-1'), []),
            ActivityCatastrophicFailure::fromStoredPayload(\Gplanchat\Durable\ExecutionId::fromString($executionId), []),
            ActivityTaskFailed::forThrowable(\Gplanchat\Durable\ExecutionId::fromString($executionId), 'a1', 'charge', 1, $e),
            ActivityFailed::fromEnvelope(\Gplanchat\Durable\ExecutionId::fromString($executionId), 'a1', $envelope),
            \Gplanchat\Durable\Failure\WorkflowFailureClassifier::classify(\Gplanchat\Durable\ExecutionId::fromString($executionId), $e),
            \Gplanchat\Durable\Failure\ActivityFailureEventFactory::fromActivityThrowable(\Gplanchat\Durable\ExecutionId::fromString($executionId), 'a1', 'charge', 1, $e),
        ];
    }
}

?>
