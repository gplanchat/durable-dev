<?php

namespace unit\DurableRector\Fixture\ExecutionIdEvent;

use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;

final class LeftAlone
{
    public function __invoke(ExecutionId $id, mixed $unknown, ?string $maybe, array $args, \Throwable $e): array
    {
        return [
            new TimerCompleted($id, 'timer-1'),
            new TimerCompleted($unknown, 'timer-1'),
            new TimerCompleted($maybe, 'timer-1'),
            new TimerCompleted(...$args),
            new TimerCompleted(executionId: 'exec-1', timerId: 'timer-1'),
            WorkflowExecutionFailed::workflowHandlerFailure($id, $e),
            new \SplFileInfo('exec-1'),
            \DateTimeImmutable::createFromFormat('Y', '2026'),
        ];
    }
}
