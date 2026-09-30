<?php
namespace unit\DurableRector\Fixture\ActivitiesParameter;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// The same, positional.
final class KeepsN6b {
    #[AsWorkflowMethod]
    public function run(string $id, WorkflowEnvironment $env): mixed {
        $o = $env->activityStub(OrderActivities::class, ActivityOptions::of(null, null, null, []));
        return $env->await($o->charge($id));
    }
}
