<?php

namespace unit\DurableRector\Fixture\FiberDriver;

use Gplanchat\Durable\InMemoryWorkflowRunner;

final class RunsAWorkflow
{
    public function __invoke(InMemoryWorkflowRunner $runner, callable $handler): mixed
    {
        return $runner->run('exec-1', $handler, 'Order');
    }
}
