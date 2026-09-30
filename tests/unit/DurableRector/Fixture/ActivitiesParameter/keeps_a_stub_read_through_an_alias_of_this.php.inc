<?php
namespace unit\DurableRector\Fixture\ActivitiesParameter;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

// $self->orders reads the stub too.
final class KeepsN8 {
    private readonly ActivityStub $orders;
    public function __construct(private WorkflowEnvironment $env) { $this->orders = $env->activityStub(OrderActivities::class); }
    #[AsWorkflowMethod]
    public function run(string $id): mixed {
        $self = $this;
        $refund = function () use ($self, $id) { return $self->orders->refund($id); };
        return $this->env->await($this->orders->charge($id));
    }
}
