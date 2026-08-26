<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;

/**
 * Lien parent/enfant persisté : en mode Messenger asynchrone, le run enfant se termine dans un
 * autre processus que le parent et doit retrouver à qui rendre son résultat.
 *
 * @see DUR030
 */
final class DbalChildWorkflowParentLinkStore implements ChildWorkflowParentLinkStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_child_workflow_parent_link',
    ) {
    }

    public function link(string $childExecutionId, string $parentExecutionId): void
    {
        $this->schema->ensure();

        $updated = $this->connection->update(
            $this->table,
            ['parent_execution_id' => $parentExecutionId],
            ['child_execution_id' => $childExecutionId],
        );
        if (0 === $updated) {
            $this->connection->insert($this->table, [
                'child_execution_id' => $childExecutionId,
                'parent_execution_id' => $parentExecutionId,
            ]);
        }
    }

    public function getParentExecutionId(string $childExecutionId): ?string
    {
        $this->schema->ensure();

        $parent = $this->connection->fetchOne(
            \sprintf('SELECT parent_execution_id FROM %s WHERE child_execution_id = ?', $this->table),
            [$childExecutionId],
        );

        return false === $parent || null === $parent ? null : (string) $parent;
    }

    public function getChildExecutionIdsForParent(string $parentExecutionId): array
    {
        $this->schema->ensure();

        return array_map(
            static fn (mixed $id): string => (string) $id,
            $this->connection->fetchFirstColumn(
                \sprintf('SELECT child_execution_id FROM %s WHERE parent_execution_id = ?', $this->table),
                [$parentExecutionId],
            ),
        );
    }

    public function unlink(string $childExecutionId): void
    {
        $this->schema->ensure();

        $this->connection->delete($this->table, ['child_execution_id' => $childExecutionId]);
    }
}
