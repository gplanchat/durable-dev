<?php

namespace unit\DurableRector\Fixture\FiberDriver;

use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Worker\WorkflowFiberDriver;
use Gplanchat\Durable\WorkflowEnvironment;

final class DrivesAFiber
{
    public function __invoke(WorkflowFiberDriver $driver, ExecutionContext $context, WorkflowEnvironment $environment, callable $handler): array
    {
        return [
            $driver->run('exec-1', $context, $environment, $handler),
            // Already migrated: a second run of the set leaves it alone.
            $driver->run($context, $environment, $handler),
        ];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\FiberDriver;

use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Worker\WorkflowFiberDriver;
use Gplanchat\Durable\WorkflowEnvironment;

final class DrivesAFiber
{
    public function __invoke(WorkflowFiberDriver $driver, ExecutionContext $context, WorkflowEnvironment $environment, callable $handler): array
    {
        return [
            $driver->run($context, $environment, $handler),
            // Already migrated: a second run of the set leaves it alone.
            $driver->run($context, $environment, $handler),
        ];
    }
}

?>
