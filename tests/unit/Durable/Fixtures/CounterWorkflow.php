<?php

declare(strict_types=1);

namespace unit\Durable\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Continues as new until `$n` reaches 2: started at 0, the chain has three runs (#802).
 */
#[AsWorkflow(name: 'counter')]
final class CounterWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(int $n): string
    {
        if ($n < 2) {
            $this->environment->continueAsNew(self::class, ['n' => $n + 1]);
        }

        return "done at {$n}";
    }
}
