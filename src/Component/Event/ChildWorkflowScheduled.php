<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ParentClosePolicy;

/**
 * Un workflow enfant est planifié depuis le parent (journal du **parent**).
 */
final readonly class ChildWorkflowScheduled implements Event
{
    public function __construct(
        private string $parentExecutionId,
        private string $childExecutionId,
        private string $childWorkflowType,
        private array $input,
        private ParentClosePolicy $parentClosePolicy = ParentClosePolicy::Terminate,
        private ?string $requestedWorkflowId = null,
    ) {
    }

    public function executionId(): string
    {
        return $this->parentExecutionId;
    }

    public function childExecutionId(): string
    {
        return $this->childExecutionId;
    }

    public function childWorkflowType(): string
    {
        return $this->childWorkflowType;
    }

    public function input(): array
    {
        return $this->input;
    }

    public function parentClosePolicy(): ParentClosePolicy
    {
        return $this->parentClosePolicy;
    }

    /**
     * Identifiant demandé par l’appelant (si différent de {@see childExecutionId()} en cas de génération auto).
     */
    public function requestedWorkflowId(): ?string
    {
        return $this->requestedWorkflowId;
    }

    public function payload(): array
    {
        return [
            'childExecutionId' => $this->childExecutionId,
            'childWorkflowType' => $this->childWorkflowType,
            'input' => $this->input,
            'parentClosePolicy' => $this->parentClosePolicy->value,
            'requestedWorkflowId' => $this->requestedWorkflowId,
        ];
    }
}
