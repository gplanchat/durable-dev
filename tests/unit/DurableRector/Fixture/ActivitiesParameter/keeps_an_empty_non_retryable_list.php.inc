<?php
namespace unit\DurableRector\Fixture\ActivitiesParameter;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// An empty list sets nothing: a bare #[Activities] would drop the default policy.
final class KeepsN6 {
    #[AsWorkflowMethod]
    public function run(string $id, WorkflowEnvironment $env): mixed {
        $o = $env->activityStub(OrderActivities::class, ActivityOptions::of(nonRetryableExceptions: []));
        return $env->await($o->charge($id));
    }
}
