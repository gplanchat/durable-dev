<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\Magento\Workflow\Activity\OrderActivities;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Un workflow ordinaire — et c'est tout l'argument.
 *
 * Rien ici ne sait qu'il tourne dans Magento : pas d'import du framework, pas de
 * `ObjectManager`, pas de `ResourceConnection`. La même classe tourne sous le
 * bundle Symfony sans être touchée, parce que tout ce qui est sous les ports est
 * `gplanchat/durable` inchangé.
 */
#[Workflow(name: 'durable.demo.place-order')]
final class PlaceOrderWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[WorkflowMethod]
    public function run(string $orderId): string
    {
        $activities = $this->environment->activityStub(OrderActivities::class);

        $receipt = $this->environment->await($activities->charge($orderId));
        $this->environment->await($activities->reserveStock($orderId));

        return $this->environment->await($activities->notifyCustomer($receipt));
    }
}
