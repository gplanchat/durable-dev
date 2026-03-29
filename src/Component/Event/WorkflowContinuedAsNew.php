<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Le run courant se termine pour enchaîner un nouveau run avec payload / type donnés.
 *
 * L’historique du **nouveau** `executionId` doit être vide ; le dispatch est à la charge du
 * {@see \Gplanchat\Durable\Bundle\Handler\WorkflowRunHandler} ou de l’appelant.
 */
final readonly class WorkflowContinuedAsNew implements Event
{
    public function __construct(
        private string $executionId,
        private string $nextWorkflowType,
        private array $nextPayload,
    ) {
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function nextWorkflowType(): string
    {
        return $this->nextWorkflowType;
    }

    public function nextPayload(): array
    {
        return $this->nextPayload;
    }

    public function payload(): array
    {
        return [
            'nextWorkflowType' => $this->nextWorkflowType,
            'nextPayload' => $this->nextPayload,
        ];
    }
}
