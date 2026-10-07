<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Store;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * The parent/child link on the journal's connection (DUR056, #751): the counterpart of
 * `DbalChildWorkflowParentLinkStore`. In asynchronous mode the child run ends in another process
 * than its parent and reads its parent here.
 *
 * No DDL and no transaction of its own: the table comes from `durable:setup`, and the caller's
 * transaction, if any, is the only one. {@see link()} is one `INSERT ... ON DUPLICATE KEY UPDATE`,
 * so linking an existing child again neither fails on the primary key (#327) nor races a
 * check-then-write.
 */
final readonly class MagentoChildWorkflowParentLinkStore implements ChildWorkflowParentLinkStoreInterface
{
    public function __construct(
        private AdapterInterface $connection,
        private JournalSchema $schema,
        private string $table = 'durable_child_workflow_parent_link',
    ) {}

    public function link(ExecutionId $childExecutionId, ExecutionId $parentExecutionId): void
    {
        $this->schema->assertInstalled();

        $this->connection->insertOnDuplicate($this->table, [
            'child_execution_id' => $childExecutionId->toString(),
            'parent_execution_id' => $parentExecutionId->toString(),
        ], ['parent_execution_id']);
    }

    public function getParentExecutionId(ExecutionId $childExecutionId): ?ExecutionId
    {
        $this->schema->assertInstalled();

        // fetchCol, not fetchOne: the latter is declared `string` and answers false on no row.
        $parents = $this->connection->fetchCol(
            $this->connection->select()->from($this->table, ['parent_execution_id'])->where('child_execution_id = ?', $childExecutionId->toString()),
        );

        return [] === $parents ? null : ExecutionId::fromString((string) $parents[0]);
    }

    public function getChildExecutionIdsForParent(ExecutionId $parentExecutionId): array
    {
        $this->schema->assertInstalled();

        return array_map(
            static fn(mixed $id): ExecutionId => ExecutionId::fromString((string) $id),
            $this->connection->fetchCol(
                $this->connection->select()->from($this->table, ['child_execution_id'])->where('parent_execution_id = ?', $parentExecutionId->toString()),
            ),
        );
    }

    public function unlink(ExecutionId $childExecutionId): void
    {
        $this->schema->assertInstalled();

        $this->connection->delete($this->table, ['child_execution_id = ?' => $childExecutionId->toString()]);
    }
}
