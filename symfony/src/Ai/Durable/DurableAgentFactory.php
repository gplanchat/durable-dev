<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use App\Ai\Guard\AgentMode;
use App\Ai\Guard\ModeToolGuard;
use App\Ai\Guard\ToolApprovalGate;
use App\Ai\Guard\ToolEffect;
use App\Ai\Guard\ToolGuardInterface;
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
     * @param array<string, array{description: string, parameters?: array<string, mixed>|null, effect?: string}> $tools
     * @param \Closure(): AgentMode|null                                                                         $mode
     */
    public static function create(
        WorkflowEnvironment $environment,
        string $model,
        array $tools,
        int $maxToolCalls = 10,
        ?ToolApprovalGate $gate = null,
        ?\Closure $mode = null,
        ?ToolGuardInterface $guard = null,
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
            ),
            maxToolCalls: $maxToolCalls,
        );
    }

    /**
     * @param array<string, array{effect?: string}> $tools
     *
     * @return array<string, ToolEffect>
     */
    private static function effects(array $tools): array
    {
        $effects = [];
        foreach ($tools as $name => $definition) {
            // Un outil qui ne déclare rien est traité comme externe par la garde : le défaut prudent.
            $effects[$name] = ToolEffect::tryFrom($definition['effect'] ?? '') ?? ToolEffect::External;
        }

        return $effects;
    }
}
