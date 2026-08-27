<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Store;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Illuminate\Database\Connection;

/**
 * Le type et le payload d'une exécution, pour la reprise.
 *
 * La subtilité du port tient en une phrase : `markCompleted()` **ne supprime pas**. Le type reste
 * lisible après le succès — un tableau de bord et un profiler en vivent — et c'est
 * `hasActiveWorkflowMetadata()`, pas `get()`, qui dit si une reprise s'applique encore. Confondre
 * les deux rend un workflow terminé éternellement reprenable, ou fait disparaître son type d'une
 * page. Les deux sens sont des cas de {@see \Gplanchat\Durable\Testing\WorkflowMetadataStoreConformanceTestCase}.
 *
 * @see DUR021
 * @see DUR041
 */
final class IlluminateWorkflowMetadataStore implements WorkflowMetadataStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_metadata',
    ) {}

    public function save(string $executionId, string $workflowType, array $payload): void
    {
        $this->schema->ensure();

        // `save()` sert aussi à repartir d'un continue-as-new : c'est un upsert, et il remet
        // `completed` à faux. `updateOrInsert()` interroge avant d'écrire, donc il ne dépend pas
        // du comptage de lignes affectées — que SQLite et MySQL ne comptent pas de la même façon.
        $this->connection->table($this->table)->updateOrInsert(
            ['execution_id' => $executionId],
            [
                'workflow_type' => $workflowType,
                'payload' => json_encode($payload, \JSON_THROW_ON_ERROR),
                'completed' => false,
            ],
        );
    }

    public function markCompleted(string $executionId): void
    {
        $this->schema->ensure();

        $this->connection->table($this->table)
            ->where('execution_id', $executionId)
            ->update(['completed' => true]);
    }

    public function get(string $executionId): ?array
    {
        $this->schema->ensure();

        $row = $this->connection->table($this->table)
            ->where('execution_id', $executionId)
            ->first();

        if (null === $row) {
            return null;
        }

        return [
            'workflowType' => (string) $row->workflow_type,
            'payload' => json_decode((string) $row->payload, true, 512, \JSON_THROW_ON_ERROR),
            'completed' => (bool) $row->completed,
        ];
    }

    public function hasActiveWorkflowMetadata(string $executionId): bool
    {
        $this->schema->ensure();

        return $this->connection->table($this->table)
            ->where('execution_id', $executionId)
            ->where('completed', false)
            ->exists();
    }

    public function delete(string $executionId): void
    {
        $this->schema->ensure();

        $this->connection->table($this->table)
            ->where('execution_id', $executionId)
            ->delete();
    }
}
