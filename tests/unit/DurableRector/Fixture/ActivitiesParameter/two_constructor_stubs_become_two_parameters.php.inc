<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class TwoConstructorStubsWorkflow
{
    private $orders;
    private $mails;

    public function __construct(private readonly WorkflowEnvironment $environment)
    {
        $this->orders = $this->environment->activityStub(OrderActivities::class);
        $this->mails = $this->environment->activityStub(MailActivities::class);
    }

    #[AsWorkflowMethod]
    public function run(string $orderId): mixed
    {
        return $this->environment->await($this->orders->charge($orderId), $this->mails->receipt($orderId));
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class TwoConstructorStubsWorkflow
{
    public function __construct(private readonly WorkflowEnvironment $environment)
    {
    }

    /**
     * @param \Gplanchat\Durable\Activity\ActivityStub<OrderActivities> $orders
     * @param \Gplanchat\Durable\Activity\ActivityStub<MailActivities> $mails
     */
    #[AsWorkflowMethod]
    public function run(string $orderId, #[\Gplanchat\Durable\Attribute\Activities(OrderActivities::class)]
    \Gplanchat\Durable\Activity\ActivityStub $orders, #[\Gplanchat\Durable\Attribute\Activities(MailActivities::class)]
    \Gplanchat\Durable\Activity\ActivityStub $mails): mixed
    {
        return $this->environment->await($orders->charge($orderId), $mails->receipt($orderId));
    }
}

?>
