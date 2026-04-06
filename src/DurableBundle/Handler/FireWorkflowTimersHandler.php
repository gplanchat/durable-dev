<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;

/**
 * Cron / message : fait progresser les timers d’un run puis relance si besoin.
 */
final class FireWorkflowTimersHandler
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ExecutionRuntime $runtime,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
    ) {
    }

    public function __invoke(FireWorkflowTimersMessage $message): void
    {
        $context = new ExecutionContext(
            $message->executionId,
            new EventStoreHistorySource($this->eventStore, $message->executionId),
            new EventStoreCommandBuffer($this->eventStore, $this->runtime->getActivityTransport(), $message->executionId),
            null,
        );

        $before = $this->countTimerCompleted($message->executionId);
        $this->runtime->checkTimers($context);
        $after = $this->countTimerCompleted($message->executionId);

        if ($after > $before) {
            $this->resumeDispatcher->dispatchResume($message->executionId);
        }
    }

    private function countTimerCompleted(string $executionId): int
    {
        $n = 0;
        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof TimerCompleted) {
                ++$n;
            }
        }

        return $n;
    }
}
