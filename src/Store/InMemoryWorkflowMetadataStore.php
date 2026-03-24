<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

/**
 * Implémentation in-memory du WorkflowMetadataStore (tests).
 */
final class InMemoryWorkflowMetadataStore implements WorkflowMetadataStore
{
    /** @var array<string, array{workflowType: string, payload: array}> */
    private array $metadata = [];

    public function save(string $executionId, string $workflowType, array $payload): void
    {
        $this->metadata[$executionId] = ['workflowType' => $workflowType, 'payload' => $payload];
    }

    public function get(string $executionId): ?array
    {
        return $this->metadata[$executionId] ?? null;
    }

    public function delete(string $executionId): void
    {
        unset($this->metadata[$executionId]);
    }
}
