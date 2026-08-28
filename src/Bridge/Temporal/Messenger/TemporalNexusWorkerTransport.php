<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Receive-only : un {@see get()} long-poll une tâche Nexus et la sert via {@see TemporalNexusWorker}.
 *
 * Même forme que {@see TemporalActivityWorkerTransport}, et pour la même raison : `messenger:consume`
 * sait déjà tenir une boucle, la relancer, la borner en temps et la superviser. Une commande console
 * dédiée redirait tout cela moins bien.
 */
final class TemporalNexusWorkerTransport implements TransportInterface
{
    public function __construct(
        private readonly TemporalNexusWorker $worker,
    ) {}

    public function get(): iterable
    {
        $this->worker->pollOnce();

        return [];
    }

    public function ack(Envelope $envelope): void {}

    public function reject(Envelope $envelope): void {}

    public function send(Envelope $envelope): Envelope
    {
        throw new LogicException('temporal nexus worker transport is receive-only.');
    }
}
