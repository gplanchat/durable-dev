<?php

declare(strict_types=1);

namespace Gplanchat\DurableSqlSpike\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/** Calls a Nexus operation: the SQL backend must refuse it by name (DUR051). */
#[AsWorkflow('SpikeNexus')]
final class SpikeNexusWorkflow
{
    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): mixed
    {
        return $env->await($env->nexusOperation('orders-endpoint', 'orders', 'reserve', ['sku' => 'X']));
    }
}
