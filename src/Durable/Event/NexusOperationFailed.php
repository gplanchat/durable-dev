<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Une opération Nexus s'est terminée autrement que par un succès.
 *
 * Un événement frère plutôt qu'un champ nullable sur la complétion, parce que c'est la forme du
 * fil : le serveur écrit des événements distincts pour l'échec, l'annulation et l'échéance. Le
 * `kind` porte lequel — l'aplatir ferait perdre à la trace ce qui distingue un refus d'un endpoint
 * tombé.
 *
 * @see \Gplanchat\Durable\Nexus\DurableNexusOperationFailedException pour les quatre valeurs
 */
final readonly class NexusOperationFailed implements Event
{
    public function __construct(
        private string $executionId,
        private string $operationId,
        private string $kind,
        private string $failureMessage,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function operationId(): string
    {
        return $this->operationId;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function failureMessage(): string
    {
        return $this->failureMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'operationId' => $this->operationId,
            'kind' => $this->kind,
            'failureMessage' => $this->failureMessage,
        ];
    }
}
