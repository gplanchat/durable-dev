<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Store;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Durable\ExecutionId;
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
final readonly class IlluminateChildWorkflowParentLinkStore implements ChildWorkflowParentLinkStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_child_workflow_parent_link',
    ) {}

    public function link(ExecutionId $childExecutionId, ExecutionId $parentExecutionId): void
    {
        $this->schema->ensure();

        // Linking an already linked child **moves** it, it does not duplicate it: the primary key
        // is the child, and that is what conformance checks.
        $this->connection->table($this->table)->updateOrInsert(
            ['child_execution_id' => $childExecutionId->toString()],
            ['parent_execution_id' => $parentExecutionId->toString()],
        );
    }

    public function getParentExecutionId(ExecutionId $childExecutionId): ?ExecutionId
    {
        $this->schema->ensure();

        $parent = $this->connection->table($this->table)
            ->where('child_execution_id', $childExecutionId->toString())
            ->value('parent_execution_id');

        return null === $parent ? null : ExecutionId::fromString((string) $parent);
    }

    public function getChildExecutionIdsForParent(ExecutionId $parentExecutionId): array
    {
        $this->schema->ensure();

        return array_map(
            static fn(mixed $id): ExecutionId => ExecutionId::fromString((string) $id),
            $this->connection->table($this->table)
                ->where('parent_execution_id', $parentExecutionId->toString())
                ->pluck('child_execution_id')
                ->all(),
        );
    }

    public function unlink(ExecutionId $childExecutionId): void
    {
        $this->schema->ensure();

        $this->connection->table($this->table)
            ->where('child_execution_id', $childExecutionId->toString())
            ->delete();
    }
}
