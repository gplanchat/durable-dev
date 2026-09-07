<?php

declare(strict_types=1);

namespace App\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Parent that starts two {@link EchoChildWorkflow} in parallel (two child workflows, each one echo activity).
 * Useful to exercise the profiler: multiple dispatches, parent + children journal, interleaved activities.
 *
 * Optionally, a durable pause ({@see WorkflowEnvironment::delay}) before the children (e.g. 10 s in the HTTP demo).
 */
#[AsWorkflow('ParallelChildEchoWorkflow')]
final class ParallelChildEchoWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    #[AsWorkflowMethod]
    public function run(string $first = 'alpha', string $second = 'beta', float $pauseSeconds = 0.0): array
    {
        if ($pauseSeconds > 0.0) {
            $this->environment->sleep($pauseSeconds, 'pause before the parallel child workflows');
        }

        // Two children started, neither awaited, then both assembled: that is exactly what
        // a stub that awaited on behalf of the caller could not express, and why this
        // workflow named its child by a string.
        $child = $this->environment->childWorkflowStub(EchoChildWorkflow::class);

        return $this->environment->await($this->environment->all(
            $child->run($first),
            $child->run($second),
        ));
    }
}
