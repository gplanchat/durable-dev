<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use App\Ai\Context\ContextBudget;
use App\Ai\Guard\AgentMode;
use App\Ai\Guard\ModeToolGuard;
use App\Ai\Guard\ToolApprovalGate;
use App\Ai\Guard\ToolEffect;
use App\Ai\Guard\ToolGuardInterface;
use App\Ai\Question\AskUserQuestion;
use App\Ai\Question\HumanQuestionDesk;
use App\Ai\Team\DelegateTool;
use App\Ai\Tool\ToolDefinition;
use App\Ai\Watch\WatchDesk;
use App\Ai\Watch\WatchTool;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Bridge\Mistral\Contract\AssistantMessageNormalizer;
use Symfony\AI\Platform\Bridge\Mistral\Contract\ToolNormalizer;
use Symfony\AI\Platform\Bridge\Mistral\Llm\ResultConverter;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog;
use Symfony\AI\Platform\Contract;
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
     * @param Duration|null              $humanTimeout échéance de toute attente humaine — validation
     *                                                 comme réponse à une question — globale à
     *                                                 cette instance d'agent
     */
    public static function create(
        WorkflowEnvironment $environment,
        string $model,
        array $tools,
        int $maxToolCalls = 10,
        ?ToolApprovalGate $gate = null,
        ?HumanQuestionDesk $desk = null,
        ?WatchDesk $watches = null,
        ?\Closure $mode = null,
        ?ToolGuardInterface $guard = null,
        ?Duration $humanTimeout = null,
        ?ContextBudget $budget = null,
    ): Agent {
        // Toujours offerts : un agent qui ne peut pas demander invente, et un agent qui ne peut
        // pas attendre bâcle.
        $tools = [...$tools, AskUserQuestion::definition(), WatchTool::definition(), DelegateTool::definition()];

        // Le pont Mistral fournit tout ce qui est **pur** — la normalisation de la conversation,
        // le catalogue, la conversion du JSON en résultat — et c'est ce qui tourne en code
        // workflow, donc rejoué. Seul son client HTTP est remplacé : lui seul sort du processus,
        // et c'est précisément ce qui doit devenir une activité.
        $platform = new Platform([
            new Provider(
                'durable-mistral',
                [new DurableModelClient($environment, $budget ?? new ContextBudget())],
                [new ResultConverter()],
                new ModelCatalog(),
                Contract::create([new AssistantMessageNormalizer(), new ToolNormalizer()]),
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
                $desk ?? new HumanQuestionDesk(),
                $watches ?? new WatchDesk(),
                $mode ?? static fn(): AgentMode => AgentMode::Auto,
                $humanTimeout,
                $model,
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
