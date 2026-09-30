<?php
namespace unit\DurableRector\Fixture\ActivitiesParameter;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// A caught exception with the stub's name would take its place.
final class KeepsA3 {
    private ActivityStub $orders;
    public function __construct(private WorkflowEnvironment $env) { $this->orders = $env->activityStub(OrderActivities::class); }
    #[AsWorkflowMethod]
    public function run(string $id): mixed { try { return $this->env->await($this->orders->charge($id)); } catch (\RuntimeException $orders) { return $this->env->await($this->orders->refund($id)); } }
}
