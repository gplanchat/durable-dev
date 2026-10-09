<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

#[AsWorkflow(name: 'bench.calls-nexus')]
final class CallsNexus
{
    public function __construct(private readonly WorkflowEnvironment $environment) {}

    #[AsWorkflowMethod]
    public function run(): mixed
    {
        return $this->environment->await($this->environment->nexusOperation('billing-endpoint', 'billing', 'charge'));
    }
}
