<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Observation\WorkflowRunStatus;

/**
 * La ligne qu'une exécution laisse derrière elle, écrite à deux plumes.
 *
 * Le **nom** ne peut venir que du magasin de métadonnées : `ExecutionStarted` ne porte pas le type
 * de workflow. L'**issue** ne peut venir que du journal : les trois fins anormales passent par le
 * même `delete()` côté métadonnées, et rien n'y distingue une annulation d'un échec.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/design.md
 * @see DUR030
 */
final class DbalWorkflowRunProjection
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_runs',
    ) {}

    /**
     * Une exécution démarre — ou reprend sous le même id.
     *
     * `started_at` n'est écrit qu'à l'insertion : le magasin de métadonnées fait un upsert, et
     * réécrire la date à chaque passage ferait rajeunir une exécution longue à chaque reprise.
     */
    public function recordStart(string $executionId, string $workflowType): void
    {
        $this->schema->ensure();

        // Même piège que dans le magasin de métadonnées : le nombre de lignes affectées par un
        // UPDATE ne dit pas la même chose sur SQLite et sur MySQL. L'existence se demande.
        $exists = false !== $this->connection->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE execution_id = ?', $this->table),
            [$executionId],
        );

        if ($exists) {
            $this->connection->update(
                $this->table,
                ['workflow_type' => $workflowType],
                ['execution_id' => $executionId],
            );
        } else {
            $this->connection->insert($this->table, [
                'execution_id' => $executionId,
                'workflow_type' => $workflowType,
                'status' => WorkflowRunStatus::Running->value,
                'started_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'ended_at' => null,
            ], [
                'started_at' => 'datetime_immutable',
                'ended_at' => 'datetime_immutable',
            ]);
        }
    }

    /**
     * L'exécution s'est terminée, d'une des quatre façons dont le journal sait parler.
     *
     * Sans effet si aucune ligne n'existe : une exécution dont le démarrage n'a pas été projeté
     * n'a pas de nom, et une ligne sans nom serait pire qu'une absence.
     */
    public function recordOutcome(string $executionId, WorkflowRunStatus $status): void
    {
        $this->schema->ensure();

        $this->connection->update(
            $this->table,
            [
                'status' => $status->value,
                'ended_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ],
            ['execution_id' => $executionId],
            ['ended_at' => 'datetime_immutable'],
        );
    }
}
