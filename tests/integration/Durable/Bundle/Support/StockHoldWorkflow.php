<?php

declare(strict_types=1);

namespace integration\Gplanchat\Durable\Bundle\Support;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[Workflow('StockHold')]
final class StockHoldWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[WorkflowMethod]
    public function run(): mixed
    {
        return $this->environment->waitUpdate('confirmQty');
    }
}
