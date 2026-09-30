<?php
namespace unit\DurableRector\Fixture\ActivitiesParameter;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// A nullsafe fetch reads the stub too.
final class KeepsA9 {
    private ?ActivityStub $orders = null;
    public function __construct(private WorkflowEnvironment $env) { $this->orders = $env->activityStub(OrderActivities::class); }
    #[AsWorkflowMethod]
    public function run(string $id): mixed { return $this->env->await($this->orders->charge($id)); }
    #[AsSignalMethod("cancel")]
    public function cancel(): void { $this?->orders?->refund("x"); }
}
