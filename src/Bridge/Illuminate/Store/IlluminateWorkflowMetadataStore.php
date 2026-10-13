<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Store;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Illuminate\Database\Connection;

/**
 * The type and payload of an execution, for resuming.
 *
 * The subtlety of the port fits in one sentence: `markCompleted()` **does not delete**. The type
 * stays readable after success — a dashboard and a profiler live off it — and it is
 * `hasActiveWorkflowMetadata()`, not `get()`, that says whether a resume still applies. Confusing
 * the two makes a finished workflow eternally resumable, or makes its type vanish from a page. Both
 * directions are cases of {@see \Gplanchat\Durable\Testing\WorkflowMetadataStoreConformanceTestCase}.
 *
 * @see DUR021
 * @see DUR041
 */
final readonly class IlluminateWorkflowMetadataStore implements WorkflowMetadataStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_workflow_metadata',
    ) {}

    public function save(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->schema->ensure();

        // `save()` also serves to restart from a continue-as-new: it is an upsert, and it resets
        // `completed` to false. `updateOrInsert()` queries before writing, so it does not depend on
        // the affected-row count — which SQLite and MySQL do not count the same way.
        $this->connection->table($this->table)->updateOrInsert(
            ['execution_id' => $executionId->toString()],
            [
                'workflow_type' => $workflowType,
                'payload' => json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
                'completed' => false,
            ],
        );
    }

    public function insertIfAbsent(ExecutionId $executionId, string $workflowType, array $payload): bool
    {
        $this->schema->ensure();

        // INSERT IGNORE / ON CONFLICT DO NOTHING: atomic, and it reports the rows it wrote.
        return 1 === $this->connection->table($this->table)->insertOrIgnore([
            'execution_id' => $executionId->toString(),
            'workflow_type' => $workflowType,
            'payload' => json_encode($payload, \JSON_THROW_ON_ERROR),
            'completed' => false,
        ]);
    }

    public function markCompleted(ExecutionId $executionId): void
    {
        $this->schema->ensure();

        $this->connection->table($this->table)
            ->where('execution_id', $executionId->toString())
            ->update(['completed' => true]);
    }

    public function get(ExecutionId $executionId): ?array
    {
        $this->schema->ensure();

        $row = $this->connection->table($this->table)
            ->where('execution_id', $executionId->toString())
            ->first();

        if (null === $row) {
            return null;
        }

        $payload = json_decode((string) $row->payload, true, 512, \JSON_THROW_ON_ERROR);

        return [
            'workflowType' => (string) $row->workflow_type,
            'payload' => \is_array($payload) ? $payload : [],
            'completed' => (bool) $row->completed,
        ];
    }

    public function hasActiveWorkflowMetadata(ExecutionId $executionId): bool
    {
        $this->schema->ensure();

        return $this->connection->table($this->table)
            ->where('execution_id', $executionId->toString())
            ->where('completed', false)
            ->exists();
    }

    public function delete(ExecutionId $executionId): void
    {
        $this->schema->ensure();

        $this->connection->table($this->table)
            ->where('execution_id', $executionId->toString())
            ->delete();
    }
}
