<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\WorkflowMetadataStore;

/**
 * Resume metadata persisted in SQL: without it, a worker receiving a
 * {@see \Gplanchat\Durable\Transport\ResumeWorkflowMessage} does not know which workflow to replay.
 *
 * @see DUR030
 */
final readonly class DbalWorkflowMetadataStore implements WorkflowMetadataStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_metadata',
    ) {}

    public function save(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->schema->ensure();

        $row = [
            'workflow_type' => $workflowType,
            'payload' => json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
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
            [$executionId->toString()],
        );

        if ($exists) {
            $this->connection->update($this->table, $row, ['execution_id' => $executionId->toString()], $types);
        } else {
            $this->connection->insert($this->table, $row + ['execution_id' => $executionId->toString()], $types);
        }
    }

    public function markCompleted(ExecutionId $executionId): void
    {
        $this->schema->ensure();

        $this->connection->update($this->table, ['completed' => true], ['execution_id' => $executionId->toString()], ['completed' => 'boolean']);
    }

    public function get(ExecutionId $executionId): ?array
    {
        $this->schema->ensure();

        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT workflow_type, payload, completed FROM %s WHERE execution_id = ?', $this->table),
            [$executionId->toString()],
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

    public function hasActiveWorkflowMetadata(ExecutionId $executionId): bool
    {
        $metadata = $this->get($executionId);

        return null !== $metadata && true !== ($metadata['completed'] ?? false);
    }

    public function delete(ExecutionId $executionId): void
    {
        $this->schema->ensure();

        $this->connection->delete($this->table, ['execution_id' => $executionId->toString()]);
    }
}
