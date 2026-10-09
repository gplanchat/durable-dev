<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime\TableQueue;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;

/**
 * A due timer travels as a `FireWorkflowTimersMessage` that `FireWorkflowTimersHandler` takes, not
 * as a delayed plain resume: the spike sent a resume, and the execution replayed 1146 times on a
 * timer that never fired (DUR056 decision 6, #726).
 */
final readonly class TableQueueWorkflowTimerDispatcher implements WorkflowTimerDispatcher
{
    public function __construct(private TableQueue $queue) {}

    public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void
    {
        $this->queue->enqueue(Queues::TIMER, Queues::encode(new FireWorkflowTimersMessage($executionId->toString())), max(0, $delayMs) / 1000);
    }
}
