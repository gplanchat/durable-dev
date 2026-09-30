<?php
namespace unit\DurableRector\Fixture\ActivitiesParameter;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// run() called here would miss the new parameter.
final class KeepsA7 {
    private ActivityStub $orders;
    public function __construct(private WorkflowEnvironment $env) { $this->orders = $env->activityStub(OrderActivities::class); }
    #[AsWorkflowMethod]
    public function run(string $id): mixed { return $this->env->await($this->orders->charge($id)); }
    public function again(string $id): mixed { return $this->run($id); }
}
