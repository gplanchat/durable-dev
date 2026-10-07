<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Store;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;

/**
 * Resume metadata on the journal's connection (DUR056, #750): the counterpart of
 * `DbalWorkflowMetadataStore`. No DDL and no transaction of its own: the tables come from
 * `durable:setup`, and the caller's transaction, if any, is the only one.
 */
final readonly class MagentoWorkflowMetadataStore implements WorkflowMetadataStore
{
    public function __construct(
        private AdapterInterface $connection,
        private JournalSchema $schema,
        private string $table = 'durable_workflow_metadata',
    ) {}

    /**
     * One upsert statement, so two concurrent saves never race between a SELECT and an INSERT.
     * `completed` goes back to 0: `save()` also starts over from a continue-as-new.
     */
    public function save(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->schema->assertInstalled();

        $this->connection->insertOnDuplicate($this->table, [
            'execution_id' => $executionId->toString(),
            'workflow_type' => $workflowType,
            'payload' => $this->encode($payload),
            'completed' => 0,
        ], ['workflow_type', 'payload', 'completed']);
    }

    /**
     * The primary key arbitrates: of two concurrent inserts, one wins and the other raises
     * Magento's duplicate-key exception (MySQL error 1062), which does not abort a transaction.
     */
    public function insertIfAbsent(ExecutionId $executionId, string $workflowType, array $payload): bool
    {
        $this->schema->assertInstalled();

        try {
            $this->connection->insert($this->table, [
                'execution_id' => $executionId->toString(),
                'workflow_type' => $workflowType,
                'payload' => $this->encode($payload),
                'completed' => 0,
            ]);
        } catch (DuplicateException) {
            return false;
        }

        return true;
    }

    public function markCompleted(ExecutionId $executionId): void
    {
        $this->schema->assertInstalled();

        $this->connection->update($this->table, ['completed' => 1], ['execution_id = ?' => $executionId->toString()]);
    }

    public function get(ExecutionId $executionId): ?array
    {
        $this->schema->assertInstalled();

        $row = $this->connection->fetchRow(
            \sprintf('SELECT workflow_type, payload, completed FROM %s WHERE execution_id = ?', $this->table),
            [$executionId->toString()],
        );

        if (!\is_array($row)) {
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

        return null !== $metadata && true !== $metadata['completed'];
    }

    public function delete(ExecutionId $executionId): void
    {
        $this->schema->assertInstalled();

        $this->connection->delete($this->table, ['execution_id = ?' => $executionId->toString()]);
    }

    private function encode(array $payload): string
    {
        return json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }
}
