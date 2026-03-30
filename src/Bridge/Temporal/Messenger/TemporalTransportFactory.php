<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * DSN unique {@code temporal://HOST:PORT?namespace=...&tls=...} pour toute connexion Temporal.
 * Le type d’accès (journal vs messages applicatifs) est choisi via {@code options.purpose}
 * ou déduit : présence de {@code inner} (DSN ou options) ⇒ {@code application}, sinon {@code journal}.
 *
 * Schémas obsolètes acceptés et normalisés : {@code temporal-journal://}, {@code temporal-application://}.
 *
 * @implements TransportFactoryInterface<TemporalJournalTransport|TemporalApplicationTransport>
 */
final class TemporalTransportFactory implements TransportFactoryInterface
{
    /**
     * @param iterable<int, TransportFactoryInterface<TransportInterface>> $transportFactories
     */
    public function __construct(
        private readonly iterable $transportFactories,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $connection = TemporalConnection::fromDsn($dsn);
        $purpose = $this->resolvePurpose($connection, $options);

        if ('journal' === $purpose) {
            return TemporalJournalTransport::fromConnection($connection);
        }

        if ('application' === $purpose) {
            $innerDsn = $connection->innerMessengerDsn
                ?? (isset($options['inner']) && \is_string($options['inner']) ? $options['inner'] : null);
            if (null === $innerDsn || '' === $innerDsn) {
                throw new \InvalidArgumentException('Temporal application transport requires inner= in the temporal:// DSN or options.inner (Messenger DSN until full gRPC).');
            }

            $inner = $this->createInnerTransport($innerDsn, $options, $serializer);

            return new TemporalApplicationTransport($connection, $inner);
        }

        throw new \InvalidArgumentException(\sprintf('Unknown temporal purpose "%s", expected journal or application.', $purpose));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        unset($options);

        return str_starts_with($dsn, 'temporal://')
            || str_starts_with($dsn, 'temporal-journal://')
            || str_starts_with($dsn, 'temporal-application://');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolvePurpose(TemporalConnection $connection, array $options): string
    {
        $explicit = $options['purpose'] ?? null;
        if (\is_string($explicit) && '' !== $explicit) {
            return $explicit;
        }

        $innerFromOptions = isset($options['inner']) && \is_string($options['inner']) && '' !== $options['inner'];
        if (null !== $connection->innerMessengerDsn || $innerFromOptions) {
            return 'application';
        }

        return 'journal';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createInnerTransport(string $innerDsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        foreach ($this->transportFactories as $factory) {
            if ($factory instanceof self) {
                continue;
            }
            if ($factory->supports($innerDsn, $options)) {
                return $factory->createTransport($innerDsn, $options, $serializer);
            }
        }

        throw new \InvalidArgumentException(\sprintf('No messenger transport factory supports inner DSN "%s".', $innerDsn));
    }
}
