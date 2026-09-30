<?php
namespace unit\DurableRector\Fixture\ActivitiesParameter;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// An arrow function parameter with the stub's name would take its place.
final class KeepsA1 {
    private ActivityStub $orders;
    public function __construct(private WorkflowEnvironment $env) { $this->orders = $env->activityStub(OrderActivities::class); }
    #[AsWorkflowMethod]
    public function run(array $ids): array { return array_map(fn($orders) => $this->orders->charge($orders), $ids); }
}
