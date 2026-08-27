<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Store;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Illuminate\Database\Connection;

/**
 * Le lien temporaire d'un run enfant vers son parent, pour finaliser le journal parent en mode
 * asynchrone.
 *
 * Le contrat ne promet pas d'ordre sur les enfants d'un parent, et ce store n'en impose donc pas :
 * la suite de conformité trie avant de comparer, précisément pour qu'un adaptateur correct ne
 * tombe pas sur une promesse que le port n'a jamais faite.
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

        // Relier un enfant déjà relié le **déplace**, il ne le duplique pas : la clé primaire est
        // l'enfant, et c'est ce que la conformité vérifie.
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
