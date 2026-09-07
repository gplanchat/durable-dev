<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Receive-only: one {@see get()} long-polls a Nexus task and serves it via {@see TemporalNexusWorker}.
 *
 * Same shape as {@see TemporalActivityWorkerTransport}, and for the same reason: `messenger:consume`
 * already knows how to hold a loop, restart it, bound it in time and supervise it. A dedicated
 * console command would redo all of that less well.
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
