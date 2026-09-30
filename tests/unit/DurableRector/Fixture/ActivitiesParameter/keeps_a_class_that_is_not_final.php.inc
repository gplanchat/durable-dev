<?php
namespace unit\DurableRector\Fixture\ActivitiesParameter;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// A subclass may override run() without the new parameter.
class KeepsA5 {
    private ActivityStub $orders;
    public function __construct(private WorkflowEnvironment $env) { $this->orders = $env->activityStub(OrderActivities::class); }
    #[AsWorkflowMethod]
    public function run(string $id): mixed { return $this->env->await($this->orders->charge($id)); }
}
final class KeepsA5Child extends KeepsA5 {
    public function run(string $id): mixed { return parent::run($id); }
}
