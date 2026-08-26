<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

/**
 * Le catalogue des exécutions, lu dans la projection.
 *
 * `groupId` reste absent : le backend DBAL n'a pas de notion de regroupement entre les exécutions
 * d'une même chaîne de continue-as-new, et l'inventer serait mentir à l'exploitant.
 *
 * @see DUR030
 */
final class DbalWorkflowRunCatalog implements WorkflowRunCatalogInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_runs',
    ) {}

    public function listRuns(int $limit = 20): array
    {
        $this->schema->ensure();

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT execution_id, workflow_type, status, started_at, ended_at FROM %s ORDER BY started_at DESC, execution_id ASC LIMIT %d',
                $this->table,
                max(1, $limit),
            ),
        );

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

        return $runs;
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
