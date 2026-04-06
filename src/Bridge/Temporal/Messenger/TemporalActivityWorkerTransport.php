<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Receive-only : un {@see get()} long-poll une tâche d’activité Temporal et l’exécute via {@see TemporalActivityWorker}.
 */
final class TemporalActivityWorkerTransport implements TransportInterface
{
    public function __construct(
        private readonly TemporalActivityWorker $worker,
    ) {
    }

    public function get(): iterable
    {
        $this->worker->pollOnce();

        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        throw new LogicException('temporal activity worker transport is receive-only.');
    }
}
