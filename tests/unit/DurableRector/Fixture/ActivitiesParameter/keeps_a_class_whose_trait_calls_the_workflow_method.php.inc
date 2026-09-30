<?php
namespace unit\DurableRector\Fixture\ActivitiesParameter;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// A trait of the class calls run() without the new parameter.
trait KeepsN4Retries { public function again(string $id, WorkflowEnvironment $env): mixed { return $this->run($id, $env); } }
final class KeepsN4 {
    use KeepsN4Retries;
    #[AsWorkflowMethod]
    public function run(string $id, WorkflowEnvironment $env): mixed {
        $orders = $env->activityStub(OrderActivities::class);
        return $env->await($orders->charge($id));
    }
}
