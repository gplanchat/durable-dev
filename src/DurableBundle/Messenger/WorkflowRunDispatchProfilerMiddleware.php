<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Messenger;

use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Transport\WorkflowRunMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Enregistre dans le profiler chaque envoi de WorkflowRunMessage (y compris vers un transport asynchrone).
 */
final class WorkflowRunDispatchProfilerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DurableExecutionTrace $trace,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if ($message instanceof WorkflowRunMessage) {
            $stamp = $envelope->last(TransportNamesStamp::class);
            $transportNames = null !== $stamp ? implode(',', $stamp->getTransportNames()) : null;
            $this->trace->onWorkflowDispatchRequested(
                $message->executionId,
                $message->workflowType,
                $message->payload,
                $message->isResume(),
                $transportNames,
            );
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
