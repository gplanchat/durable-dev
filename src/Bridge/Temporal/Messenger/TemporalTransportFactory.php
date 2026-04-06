<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Durable\WorkflowRegistry;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * DSN unique {@code temporal://HOST:PORT?namespace=...&tls=...} pour toute connexion Temporal.
 * Le type d’accès (journal vs messages applicatifs vs worker activités) est choisi via {@code options.purpose},
 * la query {@code purpose=} du DSN, ou déduit : présence de {@code inner} (DSN ou options) ⇒ {@code application}, sinon {@code journal}.
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
        private readonly ?TemporalActivityWorker $activityWorker = null,
        private readonly ?TemporalConnection $temporalConnection = null,
        private readonly ?WorkflowRegistry $workflowRegistry = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $connection = TemporalConnection::fromDsn($dsn);
        $purpose = $this->resolvePurpose($connection, $options, $dsn);

        if ('journal' === $purpose) {
            $resolved = $this->temporalConnection ?? $connection;
            if (null === $this->workflowRegistry) {
                throw new \LogicException(
                    'Temporal journal transport requires a WorkflowRegistry (enable durable.temporal.dsn in the Durable bundle).',
                );
            }

            return TemporalJournalTransport::fromConnection($resolved, $this->workflowRegistry);
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

        if ('activity_worker' === $purpose) {
            if (null === $this->activityWorker) {
                throw new \InvalidArgumentException(
                    'Temporal Messenger transport purpose=activity_worker requires TemporalActivityWorker (inject it via the Durable bundle DI or wire TemporalActivityWorkerTransport manually).',
                );
            }

            return new TemporalActivityWorkerTransport($this->activityWorker);
        }

        throw new \InvalidArgumentException(\sprintf('Unknown temporal purpose "%s", expected journal, application, or activity_worker.', $purpose));
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
    private function resolvePurpose(TemporalConnection $connection, array $options, string $dsn): string
    {
        $explicit = $options['purpose'] ?? null;
        if (\is_string($explicit) && '' !== $explicit) {
            return $explicit;
        }

        $parts = parse_url($dsn);
        if (\is_array($parts) && isset($parts['query'])) {
            parse_str($parts['query'], $q);
            if (isset($q['purpose']) && \is_string($q['purpose']) && '' !== $q['purpose']) {
                return $q['purpose'];
            }
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
