<?php

namespace unit\DurableRector\Fixture\ExecutionId;

use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\InMemoryWorkflowRunner;

final class RunsInMemory
{
    public function __invoke(InMemoryWorkflowRunner $runner, ContinueAsNewRequested $request, callable $handler): array
    {
        return [
            $runner->run('exec-1', $handler, 'Order'),
            $request->withNextExecutionId('exec-2'),
        ];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionId;

use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\InMemoryWorkflowRunner;

final class RunsInMemory
{
    public function __invoke(InMemoryWorkflowRunner $runner, ContinueAsNewRequested $request, callable $handler): array
    {
        return [
            $runner->run(\Gplanchat\Durable\ExecutionId::fromString('exec-1'), $handler, 'Order'),
            $request->withNextExecutionId(\Gplanchat\Durable\ExecutionId::fromString('exec-2')),
        ];
    }
}

?>
