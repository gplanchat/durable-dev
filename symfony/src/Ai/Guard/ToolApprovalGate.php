<?php

declare(strict_types=1);

namespace App\Ai\Guard;

use Symfony\AI\Platform\Result\ToolCall;

/**
 * L'état d'attente d'un accord humain. C'est de l'**état de workflow** : il est reconstruit par
 * rejeu depuis les signaux journalisés et les minuteurs, jamais lu d'un stockage à côté.
 *
 * C'est ce qui distingue cette validation de {@see \Symfony\AI\Agent\Toolbox\Event\ToolCallRequested}
 * de Symfony AI : `deny()` est un hook synchrone dans le processus courant, ici l'agent peut rester
 * suspendu trois jours, à travers un redéploiement, jusqu'à ce que quelqu'un tranche — ou que
 * l'échéance tranche à sa place.
 */
final class ToolApprovalGate
{
    /** @var array<string, ApprovalOutcome> */
    private array $outcomes = [];

    /** @var array<string, PendingApproval> */
    private array $pending = [];

    public function ask(ToolCall $toolCall, string $reason): void
    {
        $this->pending[$toolCall->getId()] = PendingApproval::of($toolCall, $reason);
    }

    /**
     * Une décision humaine, arrivée par signal.
     */
    public function decide(string $callId, bool $approved): void
    {
        $this->settle($callId, $approved ? ApprovalOutcome::Approved : ApprovalOutcome::Refused);
    }

    /**
     * L'échéance a tranché faute de réponse. Distinct d'un refus : personne n'a rien décidé.
     */
    public function timeout(string $callId): void
    {
        $this->settle($callId, ApprovalOutcome::Expired);
    }

    public function isSettled(string $callId): bool
    {
        return \array_key_exists($callId, $this->outcomes);
    }

    public function outcome(string $callId): ?ApprovalOutcome
    {
        return $this->outcomes[$callId] ?? null;
    }

    /**
     * @return list<PendingApproval>
     */
    public function pending(): array
    {
        return array_values($this->pending);
    }

    private function settle(string $callId, ApprovalOutcome $outcome): void
    {
        // La première issue gagne : un signal arrivé après le tir de l'échéance ne doit pas
        // ressusciter un appel que le workflow a déjà tranché — au rejeu, l'ordre du journal
        // rejouerait l'inverse.
        $this->outcomes[$callId] ??= $outcome;
        unset($this->pending[$callId]);
    }
}
