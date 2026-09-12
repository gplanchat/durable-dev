<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Wraps a Symfony Messenger transport for Durable application messages.
 *
 * Today: pure delegation to {@see $inner} (DSN {@code temporal://...?inner=} or {@code options.inner}).
 * Later: substitute the delegation with Temporal gRPC dispatch / poll while keeping the same DTOs and handlers.
 */
final class TemporalApplicationTransport implements TransportInterface
{
    public function __construct(
        private readonly TemporalConnection $connection,
        private readonly TransportInterface $inner,
    ) {}

    public function get(): iterable
    {
        return $this->inner->get();
    }

    public function ack(Envelope $envelope): void
    {
        $this->inner->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->inner->reject($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        return $this->inner->send($envelope);
    }

    public function getConnection(): TemporalConnection
    {
        return $this->connection;
    }
}
