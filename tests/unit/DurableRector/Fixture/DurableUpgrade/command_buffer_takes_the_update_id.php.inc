<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;

abstract class UpdateRecordingBuffer implements WorkflowCommandBufferInterface
{
    public function recordUpdateHandled(string $updateName, array $arguments, mixed $result, ?FailureEnvelope $failure): void
    {
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;

abstract class UpdateRecordingBuffer implements WorkflowCommandBufferInterface
{
    public function recordUpdateHandled(string $updateName, array $arguments, mixed $result, ?FailureEnvelope $failure, ?string $updateId = null): void
    {
    }
}

?>
