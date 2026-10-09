<?php

namespace unit\DurableRector\Fixture\ExecutionId;

use Gplanchat\Durable\ExecutionEngine;

final class DrivesTheEngine
{
    public function __invoke(ExecutionEngine $engine, string $executionId, callable $handler): array
    {
        return [
            $engine->start($executionId, $handler, 'Order'),
            $engine->resume('exec-1', $handler),
        ];
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\ExecutionId;

use Gplanchat\Durable\ExecutionEngine;

final class DrivesTheEngine
{
    public function __invoke(ExecutionEngine $engine, string $executionId, callable $handler): array
    {
        return [
            $engine->start(\Gplanchat\Durable\ExecutionId::fromString($executionId), $handler, 'Order'),
            $engine->resume(\Gplanchat\Durable\ExecutionId::fromString('exec-1'), $handler),
        ];
    }
}

?>
