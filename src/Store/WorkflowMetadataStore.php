<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

/**
 * Port pour persister les métadonnées de workflow (type, payload) nécessaires au re-dispatch.
 *
 * @see ADR009 Modèle distribué et re-dispatch
 */
interface WorkflowMetadataStore
{
    public function save(string $executionId, string $workflowType, array $payload): void;

    /**
     * @return array{workflowType: string, payload: array}|null
     */
    public function get(string $executionId): ?array;

    public function delete(string $executionId): void;
}
