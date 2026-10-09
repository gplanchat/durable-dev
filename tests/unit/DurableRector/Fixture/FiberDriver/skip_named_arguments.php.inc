<?php

namespace unit\DurableRector\Fixture\FiberDriver;

use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Worker\WorkflowFiberDriver;
use Gplanchat\Durable\WorkflowEnvironment;

final class DrivesAFiberByName
{
    public function __invoke(WorkflowFiberDriver $driver, ExecutionContext $context, WorkflowEnvironment $environment, callable $handler): mixed
    {
        // Named: the type checker reports the unknown executionId: parameter, the rule does not guess.
        return $driver->run(context: $context, executionId: 'exec-1', environment: $environment, handler: $handler);
    }
}
