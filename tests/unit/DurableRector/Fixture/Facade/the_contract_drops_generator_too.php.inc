<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface GeneratorTypedContract
{
    #[WorkflowMethod]
    public function run(string $orderId): \Generator;

    public function notAHandler(): \Generator;
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\Facade;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface GeneratorTypedContract
{
    #[WorkflowMethod]
    public function run(string $orderId);

    public function notAHandler(): \Generator;
}

?>
