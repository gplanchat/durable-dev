<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;

/**
 * Persisted parent/child link: in asynchronous Messenger mode, the child run ends in a different
 * process from the parent and has to find out whom to hand its result back to.
 *
 * @see DUR030
 */
final readonly class DbalChildWorkflowParentLinkStore implements ChildWorkflowParentLinkStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_child_workflow_parent_link',
    ) {}

    public function link(ExecutionId $childExecutionId, ExecutionId $parentExecutionId): void
    {
        $this->schema->ensure();

        // Existence is asked for, not inferred from the rows the UPDATE reports: MySQL counts
        // *changed* rows, so re-linking the same pair gave 0 and the INSERT that followed violated
        // the primary key (#327). The metadata store met the same trap first.
        $exists = false !== $this->connection->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE child_execution_id = ?', $this->table),
            [$childExecutionId->toString()],
        );

        if ($exists) {
            $this->connection->update(
                $this->table,
                ['parent_execution_id' => $parentExecutionId->toString()],
                ['child_execution_id' => $childExecutionId->toString()],
            );
        } else {
            $this->connection->insert($this->table, [
                'child_execution_id' => $childExecutionId->toString(),
                'parent_execution_id' => $parentExecutionId->toString(),
            ]);
        }
    }

    public function getParentExecutionId(ExecutionId $childExecutionId): ?ExecutionId
    {
        $this->schema->ensure();

        $parent = $this->connection->fetchOne(
            \sprintf('SELECT parent_execution_id FROM %s WHERE child_execution_id = ?', $this->table),
            [$childExecutionId->toString()],
        );

        return false === $parent || null === $parent ? null : ExecutionId::fromString((string) $parent);
    }

    public function getChildExecutionIdsForParent(ExecutionId $parentExecutionId): array
    {
        $this->schema->ensure();

        return array_map(
            static fn(mixed $id): ExecutionId => ExecutionId::fromString((string) $id),
            $this->connection->fetchFirstColumn(
                \sprintf('SELECT child_execution_id FROM %s WHERE parent_execution_id = ?', $this->table),
                [$parentExecutionId->toString()],
            ),
        );
    }

    public function unlink(ExecutionId $childExecutionId): void
    {
        $this->schema->ensure();

        $this->connection->delete($this->table, ['child_execution_id' => $childExecutionId->toString()]);
    }
}
