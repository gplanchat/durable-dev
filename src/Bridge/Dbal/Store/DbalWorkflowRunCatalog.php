<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

/**
 * Le catalogue des exécutions, lu dans la projection.
 *
 * La pagination est **par clé**, pas par décalage. `started_at` est stocké à la seconde et la table
 * grossit pendant qu'on la lit : un `OFFSET` ferait glisser la fenêtre à chaque exécution démarrée
 * entre deux pages, et l'exploitant verrait des lignes deux fois ou pas du tout. Le curseur porte
 * donc la dernière position lue — date *et* id —, et l'id départage les exécutions de la même
 * seconde, ce qui est le cas courant et non le cas limite.
 *
 * `groupId` reste absent : le backend DBAL n'a pas de notion de regroupement entre les exécutions
 * d'une même chaîne de continue-as-new, et l'inventer serait mentir à l'exploitant.
 *
 * @see DUR030
 */
final class DbalWorkflowRunCatalog implements WorkflowRunCatalogInterface
{
    private const BACKEND = 'SQL database';

    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_runs',
        private readonly ?JournalRunHistoryReader $history = null,
    ) {}

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
    {
        $this->schema->ensure();

        $limit = max(1, $limit);
        $where = [];
        $params = [];

        if (null !== $status) {
            $where[] = 'status = ?';
            $params[] = $status->value;
        }

        $position = self::decodeCursor($cursor);
        if (null !== $position) {
            [$startedAt, $executionId] = $position;
            $where[] = '(started_at < ? OR (started_at = ? AND execution_id > ?))';
            $params[] = $startedAt;
            $params[] = $startedAt;
            $params[] = $executionId;
        }

        // Une ligne de plus que demandé : c'est elle, et elle seule, qui dit s'il y a une suite.
        // Sans elle, une page exactement pleine promettrait une page vide.
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT execution_id, workflow_type, status, started_at, ended_at FROM %s%s ORDER BY started_at DESC, execution_id ASC LIMIT %d',
                $this->table,
                [] === $where ? '' : ' WHERE ' . implode(' AND ', $where),
                $limit + 1,
            ),
            $params,
        );

        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        $runs = [];
        foreach ($rows as $row) {
            $runs[] = new WorkflowRunDescription(
                runId: (string) $row['execution_id'],
                workflowName: (string) $row['workflow_type'],
                status: WorkflowRunStatus::from((string) $row['status']),
                startedAt: self::toDateTime($row['started_at']),
                endedAt: self::toDateTime($row['ended_at']),
            );
        }

        $last = [] === $rows ? null : $rows[\array_key_last($rows)];

        return new WorkflowRunPage(
            $runs,
            $hasMore && null !== $last
                ? self::encodeCursor((string) $last['started_at'], (string) $last['execution_id'])
                : null,
        );
    }

    /**
     * @return list<WorkflowRunEvent>
     */
    public function readHistory(WorkflowRunDescription $run): array
    {
        $reader = $this->history ?? new JournalRunHistoryReader(
            new DbalEventStore($this->connection, $this->schema),
        );

        return $reader->read($run->runId, $run->workflowName);
    }

    public function checkHealth(): BackendHealth
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            // L'ordre le plus creux que le dialecte accepte : on sonde la connexion, pas le schéma.
            // Passer par `ensure()` transformerait une base joignable mais vide en échec.
            $this->connection->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (\Throwable $failure) {
            return new BackendHealth(
                self::BACKEND,
                false,
                \sprintf('The SQL database is unreachable: %s', $failure->getMessage()),
                $checkedAt,
            );
        }

        return new BackendHealth(self::BACKEND, true, 'The SQL database answers.', $checkedAt);
    }

    private static function encodeCursor(string $startedAt, string $executionId): string
    {
        return base64_encode($startedAt . "\0" . $executionId);
    }

    /**
     * @return array{0: string, 1: string}|null
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
