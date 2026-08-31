<?php

declare(strict_types=1);

namespace App\Ai\Durable;

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
     * @param array<string, array{description: string, parameters: array<string, mixed>|null}> $tools
     */
    public static function create(
        WorkflowEnvironment $environment,
        string $model,
        array $tools,
        int $maxToolCalls = 10,
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
            toolExecutor: new DurableToolExecutor($environment),
            maxToolCalls: $maxToolCalls,
        );
    }
}
