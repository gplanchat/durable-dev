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

    /** ext-grpc when it is loaded, otherwise curl; the default, resolved once by the factory. */
    public const TRANSPORT_AUTO = 'auto';

    /** ext-grpc and the generated stub, and nothing else: fails without the extension. */
    public const TRANSPORT_GRPC = 'grpc';

    /** gRPC framing over curl/HTTP2, no extension; needs gplanchat/durable-bridge-temporal-http. */
    public const TRANSPORT_GRPC_CURL = 'grpc-curl';

    /** The server JSON gateway (port 7243): client RPCs only, no task polling; same package. */
    public const TRANSPORT_HTTP = 'http';

    public const DEFAULT_HTTP_PORT = 7243;

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
        /** One of the TRANSPORT_* constants: which client {@see WorkflowServiceClientFactory} builds. */
        public readonly string $transport = self::TRANSPORT_AUTO,
    ) {
        if (!\in_array($transport, [self::TRANSPORT_AUTO, self::TRANSPORT_GRPC, self::TRANSPORT_GRPC_CURL, self::TRANSPORT_HTTP], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown Temporal transport "%s", expected auto, grpc, grpc-curl, or http.', $transport));
        }
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
     * One DSN, whose scheme names the wire and the encryption:
     *
     *   temporal://HOST:7233        gRPC, plain      (ext-grpc when loaded, curl otherwise)
     *   temporal+tls://HOST:7233    gRPC, TLS
     *   temporal+http://HOST:7243   the server JSON gateway, plain (client RPCs only)
     *   temporal+https://HOST:7243  the server JSON gateway, TLS
     *
     * {@code tls=1} still works beside the scheme. The former journal and application schemes
     * named a worker kind, which the DSN no longer carries: they are refused, with
     * {@code temporal://} named as the replacement.
     *
     * Typical query parameters: {@code namespace}, {@code identity}, {@code task_queue} or
     * {@code journal_task_queue}, {@code workflow_type}, {@code workflow_task_queue},
     * {@code activity_task_queue}, and {@code transport} to override the choice
     * the scheme implies (auto, grpc, grpc-curl, http).
     */
    public static function fromDsn(#[\SensitiveParameter] string $dsn): self
    {
        $parts = parse_url($dsn);
        $scheme = false === $parts ? '' : strtolower($parts['scheme'] ?? '');
        if (\in_array($scheme, ['temporal-journal', 'temporal-application'], true)) {
            throw new \InvalidArgumentException(\sprintf('The %s:// scheme is no longer accepted: write temporal:// — the Durable bundle registers the workers itself, the DSN names only the server.', $scheme));
        }
        if (false === $parts || !isset(self::SCHEMES[$scheme])) {
            throw new \InvalidArgumentException('Invalid Temporal DSN: expected temporal://, temporal+tls://, temporal+http:// or temporal+https://.');
        }
        [$schemeTransport, $schemeTls] = self::SCHEMES[$scheme];

        parse_str($parts['query'] ?? '', $q);

        $transport = \is_string($q['transport'] ?? null) ? $q['transport'] : $schemeTransport;
        // The JSON gateway listens on its own port; a DSN that names the transport but not the
        // port would otherwise talk JSON to the gRPC listener and get an opaque HTTP/2 error.
        $host = $parts['host'] ?? '127.0.0.1';
        $port = isset($parts['port']) ? (int) $parts['port'] : (self::TRANSPORT_HTTP === $transport ? self::DEFAULT_HTTP_PORT : 7233);
        $target = $host . ':' . $port;

        $namespace = \is_string($q['namespace'] ?? null) ? $q['namespace'] : 'default';
        $identity = \is_string($q['identity'] ?? null) ? $q['identity'] : 'durable-temporal-bridge-php';
        $tls = $schemeTls || (isset($q['tls']) && filter_var($q['tls'], \FILTER_VALIDATE_BOOL));

        $journalTaskQueue = \is_string($q['journal_task_queue'] ?? null)
            ? $q['journal_task_queue']
            : (\is_string($q['task_queue'] ?? null) ? $q['task_queue'] : 'durable-journal');

        $workflowType = \is_string($q['workflow_type'] ?? null) ? $q['workflow_type'] : self::DEFAULT_WORKFLOW_TYPE;

        $workflowTaskQueue = \is_string($q['workflow_task_queue'] ?? null) ? $q['workflow_task_queue'] : self::DEFAULT_WORKFLOW_TASK_QUEUE;
        $activityTaskQueue = \is_string($q['activity_task_queue'] ?? null) ? $q['activity_task_queue'] : self::DEFAULT_ACTIVITY_TASK_QUEUE;
        $nexusTaskQueue = \is_string($q['nexus_task_queue'] ?? null) ? $q['nexus_task_queue'] : null;

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
            transport: $transport,
        );
    }

    /** scheme => [transport it implies, TLS it implies] */
    private const SCHEMES = [
        'temporal' => [self::TRANSPORT_AUTO, false],
        'temporal+tls' => [self::TRANSPORT_AUTO, true],
        'temporal+http' => [self::TRANSPORT_HTTP, false],
        'temporal+https' => [self::TRANSPORT_HTTP, true],
    ];

    /** Whether {@see fromDsn} accepts this DSN's scheme: one of the four. */
    public static function isTemporalDsn(#[\SensitiveParameter] string $dsn): bool
    {
        return 1 === preg_match('#^temporal(\+(tls|http|https))?://#i', $dsn);
    }
}
