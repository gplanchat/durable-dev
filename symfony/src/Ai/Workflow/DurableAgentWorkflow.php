<?php

declare(strict_types=1);

namespace App\Ai\Workflow;

use App\Ai\Durable\ChatCompletionResultConverter;
use App\Ai\Durable\DurableModelClient;
use App\Ai\Durable\DurableToolExecutor;
use App\Ai\Durable\SchemaOnlyToolbox;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;

/**
 * La boucle d'appel d'outils de Symfony AI, exécutée **en code workflow**.
 *
 * `Agent::call()` → `Runner::run()` : `while (true) { invoke model ; execute tools }`. Ses deux
 * jambes non déterministes passent par le journal ({@see DurableModelClient},
 * {@see DurableToolExecutor}), donc la boucle se rejoue et la `MessageBag` se reconstruit seule.
 *
 * Contraintes de rejeu, à ne pas relâcher :
 * - `symfony/ai` épinglé — `Runner` est `@internal`, sa boucle est le contrat de déterminisme ;
 * - pas de streaming ;
 * - schémas d'outils figés dans le payload, jamais relus du conteneur ;
 * - aucun store de messages externe : le journal est la seule source de vérité.
 */
#[AsWorkflow('Ai_DurableAgent')]
final class DurableAgentWorkflow
{
    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
    }

    /**
     * @param array<string, array{description: string, parameters: array<string, mixed>|null}> $tools
     */
    #[AsWorkflowMethod]
    public function run(string $prompt, string $model = 'gpt-4o-mini', array $tools = [], int $maxToolCalls = 10): string
    {
        $platform = new Platform([
            new Provider(
                'durable',
                [new DurableModelClient($this->environment)],
                [new ChatCompletionResultConverter()],
                new FallbackModelCatalog(),
            ),
        ]);

        $agent = new Agent(
            $platform,
            $model,
            toolbox: new SchemaOnlyToolbox($tools),
            toolExecutor: new DurableToolExecutor($this->environment),
            maxToolCalls: $maxToolCalls,
        );

        return (string) $agent->call($prompt)->getResult()->getContent();
    }
}
