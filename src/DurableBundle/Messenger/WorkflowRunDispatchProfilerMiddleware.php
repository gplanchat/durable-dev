<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Messenger;

use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Records in the profiler every dispatch of ResumeWorkflowMessage (including to an asynchronous transport).
 */
final class WorkflowRunDispatchProfilerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DurableExecutionTrace $trace,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if ($message instanceof ResumeWorkflowMessage) {
            $stamp = $envelope->last(TransportNamesStamp::class);
            $transportNames = null !== $stamp ? implode(',', $stamp->getTransportNames()) : null;
            // The message carries no type: only the stamp tells a new run from a resume.
            $newRun = $envelope->last(NewWorkflowRunStamp::class);
            $this->trace->onWorkflowDispatchRequested(
                $message->executionId,
                $newRun->workflowType ?? '',
                [],
                null === $newRun,
                $transportNames,
            );
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
