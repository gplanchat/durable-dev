<?php

declare(strict_types=1);

namespace App\Ai\Activity;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * Un outil d'agent est un effet de bord : c'est une activité, pas du code workflow.
 *
 * ponytail: un seul contrat générique pour le prototype. Un outil qui a besoin de sa propre
 * politique de retentative ou d'une compensation mérite son propre contrat d'activité.
 */
interface AgentToolActivityInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    #[AsActivityMethod('ai_tool_call')]
    public function callTool(string $callId, string $name, array $arguments): string;
}
