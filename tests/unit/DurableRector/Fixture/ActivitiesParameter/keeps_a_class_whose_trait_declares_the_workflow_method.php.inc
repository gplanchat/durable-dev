<?php
namespace unit\DurableRector\Fixture\ActivitiesParameter;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// A trait declaring run() would no longer match it.
trait KeepsN4bRunContract { abstract public function run(string $id, WorkflowEnvironment $env): mixed; }
final class KeepsN4b {
    use KeepsN4bRunContract;
    #[AsWorkflowMethod]
    public function run(string $id, WorkflowEnvironment $env): mixed {
        $orders = $env->activityStub(OrderActivities::class);
        return $env->await($orders->charge($id));
    }
}
