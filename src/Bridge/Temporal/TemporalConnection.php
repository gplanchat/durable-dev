<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\WorkflowNamespace;

/**
 * Connexion Temporal unique (cible, namespace, TLS, identité) + paramètres pour les différents accès
 * (worker journal, files applicatives, délégation Messenger transitoire).
 */
final class TemporalConnection
{
    public const DEFAULT_WORKFLOW_TYPE = 'DurableJournal';

    public const DEFAULT_JOURNAL_TASK_QUEUE = 'durable-journal';

    public const DEFAULT_WORKFLOW_TASK_QUEUE = 'durable-workflows';

    public const DEFAULT_ACTIVITY_TASK_QUEUE = 'durable-activities';

    public const DEFAULT_SIGNAL_APPEND = 'durableAppend';

    public const DEFAULT_QUERY_READ_STREAM = 'readStream';

    /** File du worker journal (poll workflow tasks). */
    public readonly TaskQueue $journalTaskQueue;

    /** File des tâches de workflow applicatives. */
    public readonly TaskQueue $workflowTaskQueue;

    /** File des tâches d'activité applicatives. */
    public readonly TaskQueue $activityTaskQueue;

    /**
     * File des tâches Nexus servies par ce composant.
     *
     * Elle n'a pas de défaut à elle : elle **suit la file de workflow**. Un endpoint Nexus vise une
     * file, et le serveur n'y livre que si quelqu'un y poll — une file par défaut que personne ne
     * sert donnerait un endpoint qui ne répond jamais, sans la moindre erreur. Suivre la file de
     * workflow fait tomber juste le montage le plus courant : un worker, une file.
     */
    public readonly TaskQueue $nexusTaskQueue;

    /** Frontière d'isolation : exécutions, files et attributs de recherche y vivent. */
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
         * DSN Messenger délégué tant que le transport applicatif n’est pas entièrement gRPC.
         * Null pour le transport journal (receive-only) ; requis pour purpose=application.
         */
        public readonly ?string $innerMessengerDsn = null,
    ) {
        // Les noms de files viennent d'un DSN : une faute y crée une file où personne ne poll,
        // sans la moindre erreur côté serveur. Ils sont validés ici, au montage.
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
     * DSN unique {@code temporal://HOST:PORT?...} ; les schémas {@code temporal-journal://}
     * et {@code temporal-application://} sont normalisés pour compatibilité ascendante.
     *
     * Paramètres de requête typiques : {@code namespace}, {@code tls}, {@code identity},
     * {@code task_queue} ou {@code journal_task_queue}, {@code workflow_type},
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
