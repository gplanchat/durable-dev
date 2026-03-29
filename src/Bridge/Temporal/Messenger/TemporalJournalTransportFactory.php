<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\TemporalJournalSettings;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * DSN: temporal-journal://HOST:PORT?namespace=default&task_queue=durable-journal&tls=0&workflow_type=DurableJournal.
 *
 * @implements TransportFactoryInterface<TemporalJournalTransport>
 */
final class TemporalJournalTransportFactory implements TransportFactoryInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        unset($options, $serializer);

        return TemporalJournalTransport::fromSettings(self::parseSettings($dsn));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        unset($options);

        return str_starts_with($dsn, 'temporal-journal://');
    }

    public static function parseSettings(string $dsn): TemporalJournalSettings
    {
        $parts = parse_url($dsn);
        if (false === $parts || !isset($parts['scheme']) || 'temporal-journal' !== $parts['scheme']) {
            throw new \InvalidArgumentException('Invalid temporal-journal DSN.');
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = isset($parts['port']) ? (int) $parts['port'] : 7233;
        $target = $host.':'.$port;

        parse_str($parts['query'] ?? '', $q);

        $namespace = \is_string($q['namespace'] ?? null) ? $q['namespace'] : 'default';
        $taskQueue = \is_string($q['task_queue'] ?? null) ? $q['task_queue'] : 'durable-journal';
        $workflowType = \is_string($q['workflow_type'] ?? null) ? $q['workflow_type'] : TemporalJournalSettings::DEFAULT_WORKFLOW_TYPE;
        $identity = \is_string($q['identity'] ?? null) ? $q['identity'] : 'durable-temporal-bridge-php';
        $tls = isset($q['tls']) && filter_var($q['tls'], \FILTER_VALIDATE_BOOL);

        return new TemporalJournalSettings(
            target: $target,
            namespace: $namespace,
            taskQueue: $taskQueue,
            workflowType: $workflowType,
            identity: $identity,
            tls: $tls,
        );
    }
}
