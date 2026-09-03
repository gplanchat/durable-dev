<?php

declare(strict_types=1);

namespace unit\DurableBundle\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;

/**
 * Un workflow sans dépendance : rien à autowirer, donc rien qui puisse piéger.
 */
#[AsWorkflow('SansRien')]
final class WorkflowSansRien
{
    #[AsWorkflowMethod]
    public function run(): string
    {
        return 'ok';
    }
}
