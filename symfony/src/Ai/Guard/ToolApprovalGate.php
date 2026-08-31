<?php

declare(strict_types=1);

namespace App\Ai\Guard;

use Symfony\AI\Platform\Result\ToolCall;

/**
 * L'état d'attente d'un accord humain. C'est de l'**état de workflow** : il est reconstruit par
 * rejeu depuis les signaux journalisés, jamais lu d'un stockage à côté.
 *
 * C'est ce qui distingue cette validation de {@see \Symfony\AI\Agent\Toolbox\Event\ToolCallRequested}
 * de Symfony AI : `deny()` est un hook synchrone dans le processus courant, ici l'agent peut rester
 * suspendu trois jours, à travers un redéploiement, jusqu'à ce que quelqu'un tranche.
 */
final class ToolApprovalGate
{
    /** @var array<string, bool> id d'appel → accordé */
    private array $decisions = [];

    /** @var array<string, PendingApproval> */
    private array $pending = [];

    public function ask(ToolCall $toolCall, string $reason): void
    {
        $this->pending[$toolCall->getId()] = PendingApproval::of($toolCall, $reason);
    }

    public function decide(string $callId, bool $approved): void
    {
        $this->decisions[$callId] = $approved;
        unset($this->pending[$callId]);
    }

    public function isDecided(string $callId): bool
    {
        return \array_key_exists($callId, $this->decisions);
    }

    public function isApproved(string $callId): bool
    {
        return $this->decisions[$callId] ?? false;
    }

    /**
     * @return list<PendingApproval>
     */
    public function pending(): array
    {
        return array_values($this->pending);
    }
}
