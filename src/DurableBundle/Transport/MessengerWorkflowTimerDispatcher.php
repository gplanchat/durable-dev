<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Transport;

use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * The timer port, held by Messenger — that is all Symfony brought to resume orchestration, and
 * that is now all that is left of it here.
 *
 * `DispatchAfterCurrentBusStamp` is what gives the contract its "after the current unit of work":
 * without it the wake-up is delivered in the middle of the pass under way, which then re-reads a
 * half-written journal.
 */
final class MessengerWorkflowTimerDispatcher implements WorkflowTimerDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function dispatchTimerFire(string $executionId, int $delayMs = 0): void
    {
        $stamps = [new DispatchAfterCurrentBusStamp()];
        if ($delayMs > 0) {
            $stamps[] = new DelayStamp($delayMs);
        }

        $this->messageBus->dispatch(new Envelope(new FireWorkflowTimersMessage($executionId), $stamps));
    }
}
