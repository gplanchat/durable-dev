<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Store\WorkflowMetadataStore;

/**
 * Métadonnées de reprise persistées en SQL : sans elles, un worker qui reçoit un
 * {@see \Gplanchat\Durable\Transport\ResumeWorkflowMessage} ne sait pas quel workflow rejouer.
 *
 * @see DUR030
 */
final class DbalWorkflowMetadataStore implements WorkflowMetadataStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_metadata',
    ) {
    }

    public function save(string $executionId, string $workflowType, array $payload): void
    {
        $this->schema->ensure();

        $row = [
            'workflow_type' => $workflowType,
            'payload' => json_encode($payload, \JSON_THROW_ON_ERROR),
            'completed' => false,
        ];

        // `save()` est aussi appelé pour repartir d'un continue-as-new : upsert, pas insert.
        $updated = $this->connection->update($this->table, $row, ['execution_id' => $executionId]);
        if (0 === $updated) {
            $this->connection->insert($this->table, $row + ['execution_id' => $executionId]);
        }
    }

    public function markCompleted(string $executionId): void
    {
        $this->schema->ensure();

        $this->connection->update($this->table, ['completed' => true], ['execution_id' => $executionId]);
    }

    public function get(string $executionId): ?array
    {
        $this->schema->ensure();

        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT workflow_type, payload, completed FROM %s WHERE execution_id = ?', $this->table),
            [$executionId],
        );

        if (false === $row) {
            return null;
        }

        $payload = json_decode((string) $row['payload'], true, 512, \JSON_THROW_ON_ERROR);

        return [
            'workflowType' => (string) $row['workflow_type'],
            'payload' => \is_array($payload) ? $payload : [],
            'completed' => (bool) $row['completed'],
        ];
    }

    public function hasActiveWorkflowMetadata(string $executionId): bool
    {
        $metadata = $this->get($executionId);

        return null !== $metadata && true !== ($metadata['completed'] ?? false);
    }

    public function delete(string $executionId): void
    {
        $this->schema->ensure();

        $this->connection->delete($this->table, ['execution_id' => $executionId]);
    }
}
