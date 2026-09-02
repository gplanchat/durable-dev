<?php

declare(strict_types=1);

namespace App\Ai\Watch;

/**
 * Les veilles en cours et les alertes reçues. État de workflow, reconstruit par rejeu.
 *
 * Troisième objet de cette forme après {@see \App\Ai\Guard\ToolApprovalGate} et
 * {@see \App\Ai\Question\HumanQuestionDesk} : une file d'attentes par identifiant d'appel, réglée
 * par signal, la première issue gagnant. Ils ne sont pas fusionnés parce que ce qu'ils portent
 * diffère — une issue à trois cas, des réponses, une observation — et qu'un objet générique
 * remplacerait trois types clairs par un `mixed`. Le jour où un quatrième arrive avec la même
 * charge que l'un des trois, ce sera le moment.
 */
final class WatchDesk
{
    /** @var array<string, string> id d'appel → observation rapportée par l'alerte */
    private array $alertes = [];

    /** @var array<string, Watch> */
    private array $pending = [];

    public function watch(Watch $watch): void
    {
        $this->pending[$watch->callId] = $watch;
    }

    /**
     * Une alerte levée de l'extérieur : un webhook, une supervision, un humain, un autre agent.
     */
    public function raise(string $callId, string $observation): void
    {
        // La première alerte gagne : une seconde arrivée après l'échéance ne doit pas rouvrir une
        // veille déjà close, sinon l'ordre du journal donnerait le verdict inverse au rejeu.
        $this->alertes[$callId] ??= trim($observation);
        unset($this->pending[$callId]);
    }

    public function isSettled(string $callId): bool
    {
        return \array_key_exists($callId, $this->alertes);
    }

    public function observationOf(string $callId): string
    {
        return $this->alertes[$callId] ?? '';
    }

    /**
     * @return list<Watch>
     */
    public function pending(): array
    {
        return array_values($this->pending);
    }
}
