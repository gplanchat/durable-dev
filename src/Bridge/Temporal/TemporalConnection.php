<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\WorkflowNamespace;

/**
 * Single Temporal connection (target, namespace, TLS, identity) + settings for the various
 * accesses (journal worker, application queues, transitional Messenger delegation).
 */
final class TemporalConnection
{
    public const DEFAULT_WORKFLOW_TYPE = 'DurableJournal';

    public const DEFAULT_JOURNAL_TASK_QUEUE = 'durable-journal';

    public const DEFAULT_WORKFLOW_TASK_QUEUE = 'durable-workflows';

    public const DEFAULT_ACTIVITY_TASK_QUEUE = 'durable-activities';

    public const DEFAULT_SIGNAL_APPEND = 'durableAppend';

    public const DEFAULT_QUERY_READ_STREAM = 'readStream';

    /** Journal worker queue (poll workflow tasks). */
    public readonly TaskQueue $journalTaskQueue;

    /** Queue for the application workflow tasks. */
    public readonly TaskQueue $workflowTaskQueue;

    /** Queue for the application activity tasks. */
    public readonly TaskQueue $activityTaskQueue;

    /**
     * Queue for the Nexus tasks served by this component.
     *
     * It has no default of its own: it **follows the workflow queue**. A Nexus endpoint targets
     * a queue, and the server only delivers there if somebody polls it — a default queue nobody
     * serves would give an endpoint that never answers, without the slightest error. Following
     * the workflow queue gets the most common setup right: one worker, one queue.
     */
    public readonly TaskQueue $nexusTaskQueue;

    /** Isolation boundary: executions, queues and search attributes live inside it. */
    public readonly WorkflowNamespace $namespace;

    public function __construct(
        public readonly string $target,
        WorkflowNamespace|string $namespace,
        TaskQueue|string|null $journalTaskQueue = null,
        public readonly string $workflowType = self::DEFAULT_WORKFLOW_TYPE,
        public readonly string $signalAppend = self::DEFAULT_SIGNAL_APPEND,
        public readonly string $queryReadStream = self::DEFAULT_QUERY_READ_STREAM,
        public readonly string $identity = 'durable-temporal-bridge-php',
        public readonly bool $tls = false,
        TaskQueue|string|null $workflowTaskQueue = null,
        TaskQueue|string|null $activityTaskQueue = null,
        TaskQueue|string|null $nexusTaskQueue = null,
        /**
         * Delegated Messenger DSN as long as the application transport is not fully gRPC.
         * Null for the journal transport (receive-only); required for purpose=application.
         */
        public readonly ?string $innerMessengerDsn = null,
    ) {
        // Queue names come from a DSN: a typo there creates a queue nobody polls, without the
        // slightest error on the server side. They are validated here, at wiring time.
        $this->namespace = WorkflowNamespace::from($namespace);
        $this->journalTaskQueue = TaskQueue::from($journalTaskQueue ?? self::DEFAULT_JOURNAL_TASK_QUEUE);
        $this->workflowTaskQueue = TaskQueue::from($workflowTaskQueue ?? self::DEFAULT_WORKFLOW_TASK_QUEUE);
        $this->activityTaskQueue = TaskQueue::from($activityTaskQueue ?? self::DEFAULT_ACTIVITY_TASK_QUEUE);
        $this->nexusTaskQueue = TaskQueue::from($nexusTaskQueue ?? $this->workflowTaskQueue);
    }

    public function journalWorkflowId(string $executionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $executionId) ?? 'invalid';

        return 'durable-journal-' . substr($safe, 0, 900);
    }

    /**
     * Single {@code temporal://HOST:PORT?...} DSN; the {@code temporal-journal://}
     * and {@code temporal-application://} schemes are normalized for backward compatibility.
     *
     * Typical query parameters: {@code namespace}, {@code tls}, {@code identity},
     * {@code task_queue} or {@code journal_task_queue}, {@code workflow_type},
     * {@code workflow_task_queue}, {@code activity_task_queue}, {@code inner}.
     */
    public static function fromDsn(#[\SensitiveParameter] string $dsn): self
    {
        $normalized = self::normalizeScheme($dsn);
        $parts = parse_url($normalized);
        if (false === $parts || !isset($parts['scheme']) || 'temporal' !== $parts['scheme']) {
            throw new \InvalidArgumentException('Invalid temporal:// DSN (or legacy temporal-journal / temporal-application).');
        }

        $host = $parts['host'] ?? '127.0.0.1';
        $port = isset($parts['port']) ? (int) $parts['port'] : 7233;
        $target = $host . ':' . $port;

        parse_str($parts['query'] ?? '', $q);

        $namespace = \is_string($q['namespace'] ?? null) ? $q['namespace'] : 'default';
        $identity = \is_string($q['identity'] ?? null) ? $q['identity'] : 'durable-temporal-bridge-php';
        $tls = isset($q['tls']) && filter_var($q['tls'], \FILTER_VALIDATE_BOOL);

        $journalTaskQueue = \is_string($q['journal_task_queue'] ?? null)
            ? $q['journal_task_queue']
            : (\is_string($q['task_queue'] ?? null) ? $q['task_queue'] : 'durable-journal');

        $workflowType = \is_string($q['workflow_type'] ?? null) ? $q['workflow_type'] : self::DEFAULT_WORKFLOW_TYPE;

        $workflowTaskQueue = \is_string($q['workflow_task_queue'] ?? null) ? $q['workflow_task_queue'] : self::DEFAULT_WORKFLOW_TASK_QUEUE;
        $activityTaskQueue = \is_string($q['activity_task_queue'] ?? null) ? $q['activity_task_queue'] : self::DEFAULT_ACTIVITY_TASK_QUEUE;
        $nexusTaskQueue = \is_string($q['nexus_task_queue'] ?? null) ? $q['nexus_task_queue'] : null;

        $inner = \is_string($q['inner'] ?? null) ? $q['inner'] : null;
        if (null !== $inner && str_starts_with($inner, 'temporal://')) {
            throw new \InvalidArgumentException('inner= must not be a temporal:// DSN (no nested bridge).');
        }

        return new self(
            target: $target,
            namespace: $namespace,
            journalTaskQueue: $journalTaskQueue,
            workflowType: $workflowType,
            signalAppend: self::DEFAULT_SIGNAL_APPEND,
            queryReadStream: self::DEFAULT_QUERY_READ_STREAM,
            identity: $identity,
            tls: $tls,
            workflowTaskQueue: $workflowTaskQueue,
            activityTaskQueue: $activityTaskQueue,
            nexusTaskQueue: $nexusTaskQueue,
            innerMessengerDsn: $inner,
        );
    }

    private static function normalizeScheme(string $dsn): string
    {
        if (str_starts_with($dsn, 'temporal-journal://')) {
            return (string) preg_replace('#^temporal-journal://#i', 'temporal://', $dsn);
        }
        if (str_starts_with($dsn, 'temporal-application://')) {
            return (string) preg_replace('#^temporal-application://#i', 'temporal://', $dsn);
        }

        return $dsn;
    }
}
