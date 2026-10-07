<?php

declare(strict_types=1);

namespace App\Tests\Functional\Fixture;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * A workflow that fails at its first step: the smallest run whose failure the journal must keep.
 * Registered in the `test` environment only, by config/packages/test/durable.yaml.
 */
#[AsWorkflow(self::TYPE)]
final class FailingWorkflow
{
    public const TYPE = 'failing';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        throw new \RuntimeException('the order cannot be placed');
    }
}
