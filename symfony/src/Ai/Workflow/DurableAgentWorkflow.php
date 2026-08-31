<?php

declare(strict_types=1);

namespace App\Ai\Workflow;

use App\Ai\Durable\DurableAgentFactory;
use App\Ai\Guard\AgentMode;
use App\Ai\Guard\ToolApprovalGate;
use App\Ai\Guard\ToolGuardInterface;
use App\Ai\Tool\ToolDefinition;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Un agent durable : **une conversation = une exécution**.
 *
 * La boucle d'appel d'outils de Symfony AI (`Agent::call()` → `Runner::run()`) tourne en code
 * workflow ; ses deux jambes non déterministes passent par le journal ({@see \App\Ai\Durable\DurableModelClient},
 * {@see \App\Ai\Durable\DurableToolExecutor}), donc elle se rejoue et la `MessageBag` se reconstruit seule.
 *
 * Un seul type pour deux usages, qui ne diffèrent que par les paramètres :
 * - `prompt` + `maxTurns: 1` — une question, une réponse, l'exécution se termine ;
 * - sans `prompt` — un chat : chaque message humain arrive par un signal `user_message`, et entre
 *   deux messages le workflow est *suspendu*, pas en attente dans un processus. Il peut le rester
 *   des jours, à travers un redéploiement.
 *
 * Chaque appel d'outil passe par une garde ({@see ToolGuardInterface}) : selon le mode il passe, il
 * est refusé, ou il suspend l'exécution jusqu'à un signal `tool_decision`. `approvalTimeoutSeconds`
 * borne cette attente pour toute l'instance d'agent — pas de réponse vaut refus, et le minuteur
 * étant journalisé (DUR032) l'échéance survit au redémarrage comme l'attente elle-même.
 *
 * Contraintes de rejeu, à ne pas relâcher : `symfony/ai` épinglé (`Runner` est `@internal`, sa
 * boucle est le contrat de déterminisme), pas de streaming, schémas d'outils figés dans le payload,
 * aucun store de messages externe — le journal est la seule source de vérité.
 *
 * ponytail: le journal grossit avec la conversation. `continueAsNew` est la sortie documentée
 * (repartir d'un résumé), à faire quand une vraie conversation le justifie.
 */
#[AsWorkflow('Ai_DurableAgent')]
final class DurableAgentWorkflow
{
    /** @var list<string> */
    private array $inbox = [];

    private bool $closed = false;

    private AgentMode $mode = AgentMode::Standard;

    private readonly ToolApprovalGate $gate;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->gate = new ToolApprovalGate();
    }

    /**
     * Une file, pas un champ : un second message posté pendant que l'agent travaille écraserait le
     * premier. L'ordre de consommation est l'ordre du journal, donc stable au rejeu.
     *
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('user_message')]
    public function onUserMessage(array $payload): void
    {
        $text = trim((string) ($payload['text'] ?? ''));
        if ('' !== $text) {
            $this->inbox[] = $text;
        }
    }

    /**
     * L'accord — ou le refus — d'un appel d'outil. L'exécution suspendue sur sa condition reprend ici.
     *
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('tool_decision')]
    public function onToolDecision(array $payload): void
    {
        $callId = (string) ($payload['callId'] ?? '');
        if ('' !== $callId) {
            $this->gate->decide($callId, (bool) ($payload['approved'] ?? false));
        }
    }

    /**
     * Changer de mode en cours de conversation est journalisé, donc rejoué à l'identique.
     *
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('set_mode')]
    public function onSetMode(array $payload): void
    {
        $this->mode = AgentMode::tryFrom((string) ($payload['mode'] ?? '')) ?? $this->mode;
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('close')]
    public function onClose(array $payload): void
    {
        $this->closed = true;
    }

    /**
     * La charge arrive du journal, donc en tableaux : `$tools` est converti en
     * {@see ToolDefinition} dès l'entrée, et plus rien en dessous ne manipule de tableau associatif.
     *
     * @param array<string, array{description?: string, parameters?: array<string, mixed>|null, effect?: string}> $tools
     *
     * @return string la dernière réponse de l'agent
     */
    #[AsWorkflowMethod]
    public function run(
        array $tools = [],
        string $model = 'gpt-4o-mini',
        string $mode = 'standard',
        string $systemPrompt = 'Tu es un assistant concis. Utilise les outils quand ils répondent mieux que toi.',
        ?string $prompt = null,
        int $maxTurns = 20,
        int $maxToolCalls = 10,
        ?float $approvalTimeoutSeconds = null,
        ?ToolGuardInterface $guard = null,
    ): string {
        $this->mode = AgentMode::tryFrom($mode) ?? AgentMode::Standard;

        // Une question posée au démarrage est le premier message de la file : rien à distinguer
        // ensuite entre elle et celles qui arriveront par signal.
        if (null !== $prompt && '' !== trim($prompt)) {
            $this->inbox[] = trim($prompt);
        }

        $agent = DurableAgentFactory::create(
            $this->environment,
            $model,
            ToolDefinition::listFromWire($tools),
            $maxToolCalls,
            gate: $this->gate,
            mode: fn(): AgentMode => $this->mode,
            guard: $guard,
            approvalTimeout: Duration::fromWireValue($approvalTimeoutSeconds),
        );

        $messages = new MessageBag(Message::forSystem($systemPrompt));
        $answer = '';
        $turns = 0;

        while (!$this->closed && $turns < $maxTurns) {
            $this->environment->await(fn(): bool => [] !== $this->inbox || $this->closed);

            if ($this->closed) {
                break;
            }

            $messages->add(Message::ofUser(array_shift($this->inbox)));

            // `Runner` ajoute lui-même les messages de la boucle d'outils au sac, mais pas la
            // réponse finale : elle sort de la boucle sans y passer.
            $result = $agent->call($messages)->getResult();
            $messages->add(Message::ofAssistant($result));
            $answer = (string) $result->getContent();

            ++$turns;
        }

        return $answer;
    }
}
