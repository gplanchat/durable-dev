<?php

declare(strict_types=1);

namespace unit\Durable\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Continues as new on every run: its chain has no last run (#888).
 */
#[AsWorkflow(name: 'forever')]
final class ForeverWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(int $n): string
    {
        $this->environment->continueAsNew(self::class, ['n' => $n + 1]);
    }
}
