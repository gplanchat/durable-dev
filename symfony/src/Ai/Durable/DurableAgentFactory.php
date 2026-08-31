<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use App\Ai\Guard\AgentMode;
use App\Ai\Guard\ModeToolGuard;
use App\Ai\Guard\ToolApprovalGate;
use App\Ai\Guard\ToolEffect;
use App\Ai\Guard\ToolGuardInterface;
use App\Ai\Tool\ToolDefinition;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;

/**
 * Monte un `Agent` Symfony AI dont les deux jambes non déterministes passent par le journal.
 *
 * Le montage est du code workflow : il est réexécuté à chaque rejeu, donc il doit rester pur —
 * pas de lecture de conteneur, pas d'horloge, pas de hasard.
 */
final class DurableAgentFactory
{
    /**
     * @param list<ToolDefinition>       $tools
     * @param \Closure(): AgentMode|null $mode
     * @param Duration|null              $approvalTimeout échéance des demandes de validation, globale
     *                                                    à cette instance d'agent
     */
    public static function create(
        WorkflowEnvironment $environment,
        string $model,
        array $tools,
        int $maxToolCalls = 10,
        ?ToolApprovalGate $gate = null,
        ?\Closure $mode = null,
        ?ToolGuardInterface $guard = null,
        ?Duration $approvalTimeout = null,
    ): Agent {
        $platform = new Platform([
            new Provider(
                'durable',
                [new DurableModelClient($environment)],
                [new ChatCompletionResultConverter()],
                new FallbackModelCatalog(),
            ),
        ]);

        return new Agent(
            $platform,
            $model,
            toolbox: new SchemaOnlyToolbox($tools),
            toolExecutor: new DurableToolExecutor(
                $environment,
                $guard ?? new ModeToolGuard(self::effects($tools)),
                $gate ?? new ToolApprovalGate(),
                $mode ?? static fn(): AgentMode => AgentMode::Auto,
                $approvalTimeout,
            ),
            maxToolCalls: $maxToolCalls,
        );
    }

    /**
     * @param list<ToolDefinition> $tools
     *
     * @return array<string, ToolEffect>
     */
    private static function effects(array $tools): array
    {
        $effects = [];
        foreach ($tools as $definition) {
            $effects[$definition->name] = $definition->effect;
        }

        return $effects;
    }
}
