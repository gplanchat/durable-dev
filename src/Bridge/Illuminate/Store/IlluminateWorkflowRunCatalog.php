<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Store;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Illuminate\Database\Connection;

/**
 * Quelles exécutions existent, et ce qu'elles sont devenues — côté Laravel.
 *
 * Lecture **et** écriture dans le même objet, comme le catalogue in-memory et contrairement au pont
 * DBAL qui sépare `DbalWorkflowRunProjection` de son catalogue. Ce n'est pas une simplification :
 * les deux moitiés vivent sur une seule connexion et une seule table, il n'y a rien à partager
 * entre elles qu'un nom de table. Les décorateurs du cœur — {@see \Gplanchat\Durable\Store\ProjectingEventStore}
 * et {@see \Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore} — attendent un
 * {@see WorkflowRunProjectionInterface} et se branchent donc dessus sans rien savoir d'Illuminate.
 *
 * Le curseur est un couple `(started_at, execution_id)` encodé en base64, exactement comme côté
 * DBAL : un décalage ferait glisser la fenêtre à chaque insertion concurrente, et `started_at`
 * seul ne départage pas une salve d'exécutions démarrées dans la même seconde. La suite de
 * conformité crée les siennes d'un trait pour tomber précisément dans ce cas.
 *
 * @see DUR037 l'observation d'un run est une projection
 * @see DUR041
 */
final class IlluminateWorkflowRunCatalog implements WorkflowRunCatalogInterface, WorkflowRunProjectionInterface
{
    private const BACKEND = 'Laravel database';

    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_runs',
        private readonly ?JournalRunHistoryReader $history = null,
    ) {}

    // -- côté écriture : WorkflowRunProjectionInterface ------------------------------------------

    public function recordStart(string $executionId, string $workflowType): void
    {
        $this->schema->ensure();

        $known = $this->connection->table($this->table)
            ->where('execution_id', $executionId)
            ->exists();

        if ($known) {
            // Un continue-as-new réécrit le type sans effacer la date de départ : la ligne garde
            // sa place dans l'ordre, et le curseur qui la désigne reste valide.
            $this->connection->table($this->table)
                ->where('execution_id', $executionId)
                ->update(['workflow_type' => $workflowType]);

            return;
        }

        $this->connection->table($this->table)->insert([
            'execution_id' => $executionId,
            'workflow_type' => $workflowType,
            'status' => WorkflowRunStatus::Running->value,
            'started_at' => self::now(),
            'ended_at' => null,
        ]);
    }

    public function recordOutcome(string $executionId, WorkflowRunStatus $status): void
    {
        $this->schema->ensure();

        $this->connection->table($this->table)
            ->where('execution_id', $executionId)
            ->update(['status' => $status->value, 'ended_at' => self::now()]);
    }

    // -- côté lecture : WorkflowRunCatalogInterface ----------------------------------------------

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
    {
        $this->schema->ensure();
        $limit = max(1, $limit);

        $query = $this->connection->table($this->table)
            ->select(['execution_id', 'workflow_type', 'status', 'started_at', 'ended_at'])
            ->orderByDesc('started_at')
            ->orderBy('execution_id');

        if (null !== $status) {
            $query->where('status', $status->value);
        }

        $position = self::decodeCursor($cursor);
        if (null !== $position) {
            [$startedAt, $executionId] = $position;
            $query->where(function ($clause) use ($startedAt, $executionId): void {
                $clause->where('started_at', '<', $startedAt)
                    ->orWhere(function ($tie) use ($startedAt, $executionId): void {
                        $tie->where('started_at', $startedAt)
                            ->where('execution_id', '>', $executionId);
                    });
            });
        }

        // Une ligne de plus que demandé : c'est elle, et elle seule, qui dit s'il y a une suite.
        // Sans elle, une page exactement pleine promettrait une page vide.
        $rows = $query->limit($limit + 1)->get()->all();
        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        $runs = [];
        foreach ($rows as $row) {
            $runs[] = new WorkflowRunDescription(
                runId: (string) $row->execution_id,
                workflowName: (string) $row->workflow_type,
                status: WorkflowRunStatus::from((string) $row->status),
                startedAt: self::toDateTime($row->started_at),
                endedAt: self::toDateTime($row->ended_at),
            );
        }

        $last = [] === $rows ? null : $rows[\array_key_last($rows)];

        return new WorkflowRunPage(
            $runs,
            $hasMore && null !== $last
                ? self::encodeCursor((string) $last->started_at, (string) $last->execution_id)
                : null,
        );
    }

    public function readHistory(WorkflowRunDescription $run): array
    {
        $reader = $this->history ?? new JournalRunHistoryReader(
            new IlluminateEventStore($this->connection, $this->schema),
        );

        return $reader->read($run->runId, $run->workflowName);
    }

    public function checkHealth(): BackendHealth
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            // On sonde la connexion, pas le schéma : passer par `ensure()` transformerait une base
            // joignable mais vide en échec.
            $this->connection->select('SELECT 1');
        } catch (\Throwable $failure) {
            return new BackendHealth(
                self::BACKEND,
                false,
                \sprintf('The database is unreachable: %s', $failure->getMessage()),
                $checkedAt,
            );
        }

        return new BackendHealth(self::BACKEND, true, 'The database answers.', $checkedAt);
    }

    // -------------------------------------------------------------------------------------------

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private static function encodeCursor(string $startedAt, string $executionId): string
    {
        return base64_encode($startedAt . "\0" . $executionId);
    }

    /**
     * @return array{string, string}|null
     */
    private static function decodeCursor(?string $cursor): ?array
    {
        if (null === $cursor || '' === $cursor) {
            return null;
        }

        $raw = base64_decode($cursor, true);
        if (false === $raw || !str_contains($raw, "\0")) {
            return null;
        }

        [$startedAt, $executionId] = explode("\0", $raw, 2);

        return '' === $startedAt ? null : [$startedAt, $executionId];
    }

    private static function toDateTime(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw instanceof \DateTimeImmutable) {
            return $raw;
        }
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
    }
}
