<?php

declare(strict_types=1);

namespace App\Ai\Guard;

use Symfony\AI\Platform\Result\ToolCall;

/**
 * L'équivalent d'un hook Claude Code, mais évalué **en code workflow**.
 *
 * Conséquence à ne pas perdre de vue : une garde est rejouée à chaque reprise, donc elle doit être
 * pure. Une garde qui lit une base ou une horloge ferait diverger le rejeu — si une décision a
 * besoin d'un fait extérieur, ce fait doit passer par une activité, pas par la garde.
 */
interface ToolGuardInterface
{
    public function decide(ToolCall $toolCall, AgentMode $mode): ToolDecision;
}
