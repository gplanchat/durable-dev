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
 * Enregistre dans le profiler chaque envoi de ResumeWorkflowMessage (y compris vers un transport asynchrone).
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
        if ($message instanceof ResumeWorkflowMessage) {
            $stamp = $envelope->last(TransportNamesStamp::class);
            $transportNames = null !== $stamp ? implode(',', $stamp->getTransportNames()) : null;
            $this->trace->onWorkflowDispatchRequested(
                $message->executionId,
                '',
                [],
                true,
                $transportNames,
            );
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
