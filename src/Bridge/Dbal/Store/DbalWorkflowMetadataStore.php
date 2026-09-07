<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Store\WorkflowMetadataStore;

/**
 * Resume metadata persisted in SQL: without it, a worker receiving a
 * {@see \Gplanchat\Durable\Transport\ResumeWorkflowMessage} does not know which workflow to replay.
 *
 * @see DUR030
 */
final class DbalWorkflowMetadataStore implements WorkflowMetadataStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_metadata',
    ) {}

    public function save(string $executionId, string $workflowType, array $payload): void
    {
        $this->schema->ensure();

        $row = [
            'workflow_type' => $workflowType,
            'payload' => json_encode($payload, \JSON_THROW_ON_ERROR),
            'completed' => false,
        ];

        // The type of `completed` is declared on every write: without it, PDO binds a PHP `false`
        // as an empty string, and MySQL in strict mode refuses `''` for an integer column. SQLite
        // accepts it, which leaves the fault invisible to the whole unit suite.
        $types = ['completed' => 'boolean'];

        // `save()` is also called to start over from a continue-as-new: upsert, not insert.
        //
        // Existence is asked for rather than inferred from the number of rows affected by the
        // UPDATE: SQLite counts *matching* rows, MySQL counts *changed* rows. Re-saving identical
        // metadata therefore gives 0 on MySQL, and the INSERT that followed violated the primary
        // key. One more round trip, but the same behaviour everywhere.
        $exists = false !== $this->connection->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE execution_id = ?', $this->table),
            [$executionId],
        );

        if ($exists) {
            $this->connection->update($this->table, $row, ['execution_id' => $executionId], $types);
        } else {
            $this->connection->insert($this->table, $row + ['execution_id' => $executionId], $types);
        }
    }

    public function markCompleted(string $executionId): void
    {
        $this->schema->ensure();

        $this->connection->update($this->table, ['completed' => true], ['execution_id' => $executionId], ['completed' => 'boolean']);
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
