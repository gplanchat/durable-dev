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
 * Le port des minuteries, tenu par Messenger — c'est tout ce que Symfony apportait à
 * l'orchestration de reprise, et c'est maintenant tout ce qu'il en reste ici.
 *
 * `DispatchAfterCurrentBusStamp` est ce qui donne au contrat son « après l'unité de travail
 * courante » : sans lui le réveil se délivre au milieu de la passe en cours, qui relit alors un
 * journal à moitié écrit.
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
