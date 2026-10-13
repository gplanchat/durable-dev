<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;

/**
 * The workflow class {@see WorkflowClientPortConformanceTestCase} points its repository at.
 */
#[AsWorkflow(ConformanceClientWorkflow::TYPE)]
final class ConformanceClientWorkflow
{
    public const TYPE = 'durable.conformance.client';

    #[AsWorkflowMethod]
    public function run(): void {}
}
