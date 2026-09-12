<?php

declare(strict_types=1);

namespace unit\DurableLaravel\Fixtures;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * An ordinary workflow, and that is the whole point: it imports nothing from Laravel or Symfony.
 *
 * The only symbols it knows come from `Gplanchat\Durable\`, so the same class is declared to the
 * Symfony bundle and to this package without a line of difference. What the host changes is where
 * the journal lands and who drains the queue, never the class.
 */
#[AsWorkflow('Greeting')]
final class GreetingWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(string $who = 'world'): string
    {
        return 'hello ' . $who;
    }
}
