<?php

namespace unit\DurableRector\Fixture\ActivitiesParameter;

use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

final class LocalStubOverwrittenWorkflow
{
    #[AsWorkflowMethod]
    public function run(string $orderId, WorkflowEnvironment $env): mixed
    {
        $orders = $env->activityStub(OrderActivities::class);
        $mails = $env->activityStub(MailActivities::class);
        [$orders] = [$orderId];
        try {
            return $env->await($mails->receipt($orderId));
        } catch (\RuntimeException $mails) {
            return $orders;
        }
    }
}
