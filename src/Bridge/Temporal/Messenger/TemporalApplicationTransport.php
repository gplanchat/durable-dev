<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Enveloppe un transport Symfony Messenger pour les messages applicatifs Durable.
 *
 * Aujourd’hui : délégation pure vers {@see $inner} (DSN {@code temporal://...?inner=} ou {@code options.inner}).
 * Évolution : substituer la délégation par envoi / poll gRPC Temporal tout en conservant les mêmes DTOs et handlers.
 */
final class TemporalApplicationTransport implements TransportInterface
{
    public function __construct(
        private readonly TemporalConnection $connection,
        private readonly TransportInterface $inner,
    ) {
    }

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
