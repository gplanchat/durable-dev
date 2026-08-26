<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Une opération Nexus a été planifiée vers un endpoint extérieur.
 *
 * L'identité est celle de l'événement de planification : le message du serveur n'en porte aucune
 * autre, contrairement à une activité qui porte son `activityId` (ADR §7.1 du change
 * temporal-nexus-support).
 *
 * Aucun backend de journal ne produit cet événement — le backend in-memory refuse Nexus. Il naît
 * de la traduction de l'historique Temporal, pour que le profileur et le magasin en lecture
 * traversante montrent l'appel au lieu d'un trou.
 */
final readonly class NexusOperationScheduled implements Event
{
    public function __construct(
        private string $executionId,
        private string $operationId,
        private string $endpoint,
        private string $service,
        private string $operationName,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function operationId(): string
    {
        return $this->operationId;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function service(): string
    {
        return $this->service;
    }

    public function operationName(): string
    {
        return $this->operationName;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'operationId' => $this->operationId,
            'endpoint' => $this->endpoint,
            'service' => $this->service,
            'operationName' => $this->operationName,
        ];
    }
}
