<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

/**
 * Configuration for the Temporal journal workflow + worker (gRPC, no SDK).
 */
final class TemporalJournalSettings
{
    public const DEFAULT_WORKFLOW_TYPE = 'DurableJournal';

    public const DEFAULT_SIGNAL_APPEND = 'durableAppend';

    public const DEFAULT_QUERY_READ_STREAM = 'readStream';

    public function __construct(
        public readonly string $target,
        public readonly string $namespace,
        public readonly string $taskQueue,
        public readonly string $workflowType = self::DEFAULT_WORKFLOW_TYPE,
        public readonly string $signalAppend = self::DEFAULT_SIGNAL_APPEND,
        public readonly string $queryReadStream = self::DEFAULT_QUERY_READ_STREAM,
        public readonly string $identity = 'durable-temporal-bridge-php',
        public readonly bool $tls = false,
    ) {
    }

    public function journalWorkflowId(string $executionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $executionId) ?? 'invalid';

        return 'durable-journal-'.substr($safe, 0, 900);
    }
}
