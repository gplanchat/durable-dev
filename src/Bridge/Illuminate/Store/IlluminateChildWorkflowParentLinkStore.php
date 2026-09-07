<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Store;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Illuminate\Database\Connection;

/**
 * The temporary link from a child run to its parent, used to finalize the parent journal in
 * asynchronous mode.
 *
 * The contract promises no ordering over the children of a parent, so this store imposes none: the
 * conformance suite sorts before comparing, precisely so that a correct adapter does not trip over
 * a promise the port never made.
 *
 * @see DUR041
 */
final class IlluminateChildWorkflowParentLinkStore implements ChildWorkflowParentLinkStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_child_workflow_parent_link',
    ) {}

    public function link(string $childExecutionId, string $parentExecutionId): void
    {
        $this->schema->ensure();

        // Linking an already linked child **moves** it, it does not duplicate it: the primary key
        // is the child, and that is what conformance checks.
        $this->connection->table($this->table)->updateOrInsert(
            ['child_execution_id' => $childExecutionId],
            ['parent_execution_id' => $parentExecutionId],
        );
    }

    public function getParentExecutionId(string $childExecutionId): ?string
    {
        $this->schema->ensure();

        $parent = $this->connection->table($this->table)
            ->where('child_execution_id', $childExecutionId)
            ->value('parent_execution_id');

        return null === $parent ? null : (string) $parent;
    }

    public function getChildExecutionIdsForParent(string $parentExecutionId): array
    {
        $this->schema->ensure();

        return array_map(
            static fn(mixed $id): string => (string) $id,
            $this->connection->table($this->table)
                ->where('parent_execution_id', $parentExecutionId)
                ->pluck('child_execution_id')
                ->all(),
        );
    }

    public function unlink(string $childExecutionId): void
    {
        $this->schema->ensure();

        $this->connection->table($this->table)
            ->where('child_execution_id', $childExecutionId)
            ->delete();
    }
}
