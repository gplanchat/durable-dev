<?php

namespace unit\DurableRector\Fixture\ExecutionId;

use Gplanchat\Durable\Debug\WorkflowDispatchObserverInterface;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Observation\WorkflowRunWaitProjectionInterface;
use Gplanchat\Durable\ParentClosureReason;
use Gplanchat\Durable\Port\ActivityAttemptClaimInterface;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Gplanchat\Durable\Transport\ActivityTransportInterface;

final class RuntimeCaller
{
    public function __invoke(
        WorkflowRunProjectionInterface $runs,
        WorkflowRunWaitProjectionInterface $waits,
        WorkflowRunPickupProjectionInterface $pickups,
        WorkflowDispatchObserverInterface $dispatches,
        WorkflowExecutionObserverInterface $executions,
        ActivityTransportInterface $transport,
        ParentChildWorkflowCoordinatorInterface $coordinator,
        WorkflowLifecycleInterface $lifecycle,
        WorkflowTimerDispatcher $timers,
        WorkflowCommandBufferInterface $buffer,
        ChildWorkflowRunnerInterface $children,
        ActivityAttemptClaimInterface $claims,
        FencedEventStoreInterface $events,
        string $executionId,
        string $parentId,
        WorkflowRunStatus $status,
        ParentClosureReason $reason,
        \Gplanchat\Durable\Exception\WorkflowCancelledFailure $cancelled,
        \Gplanchat\Durable\Awaitable\Awaitable $pending,
        \Gplanchat\Durable\Exception\ContinueAsNewRequested $next,
        \Throwable $failure,
        \Gplanchat\Durable\ChildWorkflowOptions $options,
    ): void {
        $runs->recordStart($executionId, 'Order');
        $runs->recordOutcome($executionId, $status);
        $waits->recordWait($executionId, null);
        $pickups->recordPickup($executionId);
        $dispatches->onWorkflowDispatchRequested($executionId, 'Order', [], false, null);
        $executions->onWorkflowRun($executionId, 'Order', false);
        $executions->onActivityExecuted($executionId, 'a1', 'charge', 0.1, true, null);
        $transport->removePendingFor($executionId, 'a1');
        $coordinator->onParentClosed($parentId, $reason);
        $lifecycle->onBeforeRun($executionId);
        $lifecycle->isCancellationPending($executionId);
        $lifecycle->onCancellationDelivered($executionId, []);
        $lifecycle->onCompleted($executionId, null);
        $lifecycle->onCancelled($executionId, $cancelled);
        $lifecycle->onSuspended($executionId, $pending);
        $lifecycle->onContinuedAsNew($executionId, $next);
        $lifecycle->onFailed($executionId, $failure);
        $timers->dispatchTimerFire($executionId);
        $buffer->completeChildWorkflow('child-1', null);
        $buffer->scheduleChildWorkflow('child-' . $executionId, 'Order', [], $options);
        $buffer->failChildWorkflow('child-1', $failure);
        $buffer->startTimer('t1', \Gplanchat\Durable\Duration::seconds(1), '');
        $children->runChild('child-1', 'Order', [], $parentId);
        $claims->claim($executionId, 'a1', 1);
        $events->claimPass($executionId);
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionId;

use Gplanchat\Durable\Debug\WorkflowDispatchObserverInterface;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Observation\WorkflowRunWaitProjectionInterface;
use Gplanchat\Durable\ParentClosureReason;
use Gplanchat\Durable\Port\ActivityAttemptClaimInterface;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Gplanchat\Durable\Transport\ActivityTransportInterface;

final class RuntimeCaller
{
    public function __invoke(
        WorkflowRunProjectionInterface $runs,
        WorkflowRunWaitProjectionInterface $waits,
        WorkflowRunPickupProjectionInterface $pickups,
        WorkflowDispatchObserverInterface $dispatches,
        WorkflowExecutionObserverInterface $executions,
        ActivityTransportInterface $transport,
        ParentChildWorkflowCoordinatorInterface $coordinator,
        WorkflowLifecycleInterface $lifecycle,
        WorkflowTimerDispatcher $timers,
        WorkflowCommandBufferInterface $buffer,
        ChildWorkflowRunnerInterface $children,
        ActivityAttemptClaimInterface $claims,
        FencedEventStoreInterface $events,
        string $executionId,
        string $parentId,
        WorkflowRunStatus $status,
        ParentClosureReason $reason,
        \Gplanchat\Durable\Exception\WorkflowCancelledFailure $cancelled,
        \Gplanchat\Durable\Awaitable\Awaitable $pending,
        \Gplanchat\Durable\Exception\ContinueAsNewRequested $next,
        \Throwable $failure,
        \Gplanchat\Durable\ChildWorkflowOptions $options,
    ): void {
        $runs->recordStart(\Gplanchat\Durable\ExecutionId::fromString($executionId), 'Order');
        $runs->recordOutcome(\Gplanchat\Durable\ExecutionId::fromString($executionId), $status);
        $waits->recordWait(\Gplanchat\Durable\ExecutionId::fromString($executionId), null);
        $pickups->recordPickup(\Gplanchat\Durable\ExecutionId::fromString($executionId));
        $dispatches->onWorkflowDispatchRequested(\Gplanchat\Durable\ExecutionId::fromString($executionId), 'Order', [], false, null);
        $executions->onWorkflowRun(\Gplanchat\Durable\ExecutionId::fromString($executionId), 'Order', false);
        $executions->onActivityExecuted(\Gplanchat\Durable\ExecutionId::fromString($executionId), 'a1', 'charge', 0.1, true, null);
        $transport->removePendingFor(\Gplanchat\Durable\ExecutionId::fromString($executionId), 'a1');
        $coordinator->onParentClosed(\Gplanchat\Durable\ExecutionId::fromString($parentId), $reason);
        $lifecycle->onBeforeRun(\Gplanchat\Durable\ExecutionId::fromString($executionId));
        $lifecycle->isCancellationPending(\Gplanchat\Durable\ExecutionId::fromString($executionId));
        $lifecycle->onCancellationDelivered(\Gplanchat\Durable\ExecutionId::fromString($executionId), []);
        $lifecycle->onCompleted(\Gplanchat\Durable\ExecutionId::fromString($executionId), null);
        $lifecycle->onCancelled(\Gplanchat\Durable\ExecutionId::fromString($executionId), $cancelled);
        $lifecycle->onSuspended(\Gplanchat\Durable\ExecutionId::fromString($executionId), $pending);
        $lifecycle->onContinuedAsNew(\Gplanchat\Durable\ExecutionId::fromString($executionId), $next);
        $lifecycle->onFailed(\Gplanchat\Durable\ExecutionId::fromString($executionId), $failure);
        $timers->dispatchTimerFire(\Gplanchat\Durable\ExecutionId::fromString($executionId));
        $buffer->completeChildWorkflow(\Gplanchat\Durable\ExecutionId::fromString('child-1'), null);
        $buffer->scheduleChildWorkflow(\Gplanchat\Durable\ExecutionId::fromString('child-' . $executionId), 'Order', [], $options);
        $buffer->failChildWorkflow(\Gplanchat\Durable\ExecutionId::fromString('child-1'), $failure);
        $buffer->startTimer('t1', \Gplanchat\Durable\Duration::seconds(1), '');
        $children->runChild(\Gplanchat\Durable\ExecutionId::fromString('child-1'), 'Order', [], \Gplanchat\Durable\ExecutionId::fromString($parentId));
        $claims->claim(\Gplanchat\Durable\ExecutionId::fromString($executionId), 'a1', 1);
        $events->claimPass(\Gplanchat\Durable\ExecutionId::fromString($executionId));
    }
}

?>
