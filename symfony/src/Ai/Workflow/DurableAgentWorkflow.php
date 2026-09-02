<?php

declare(strict_types=1);

namespace App\Ai\Workflow;

use App\Ai\Context\ContextBudget;
use App\Ai\Durable\DurableAgentFactory;
use App\Ai\Guard\AgentMode;
use App\Ai\Guard\ToolApprovalGate;
use App\Ai\Guard\ToolGuardInterface;
use App\Ai\Question\HumanQuestionDesk;
use App\Ai\Tool\ToolDefinition;
use App\Ai\Watch\WatchDesk;
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
 * est refusé, ou il suspend l'exécution jusqu'à un signal `tool_decision`.
 *
 * L'agent dispose en plus de deux outils dont l'exécution est une suspension :
 * `demander_a_l_utilisateur` attend un signal `question_answered` — là l'humain autorise, ici il
 * renseigne — et `surveiller` attend un signal `alerte`, levé par le dehors. Le réveil rend à
 * l'agent l'observation **et l'intention qu'il avait écrite en s'inscrivant** : il n'a rien à se
 * rappeler, le journal le lui dit.
 *
 * `humanTimeoutSeconds` borne toute attente humaine pour l'instance d'agent : pas de réponse vaut
 * refus pour une validation, « rien choisi » pour une question. Le minuteur étant journalisé
 * (DUR032), l'échéance survit au redémarrage comme l'attente elle-même.
 *
 * `contextTokens` borne la conversation : une conversation durable grossit sans fin, et le jour
 * où elle dépasse la fenêtre du modèle l'agent ne rate pas un tour, il ne peut plus en faire un
 * seul. La compaction abandonne les tours les plus anciens — par tours entiers, pour ne pas
 * laisser de résultat d'outil orphelin — et elle est **pure**, donc rejouée à l'identique.
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

    private readonly HumanQuestionDesk $desk;

    private readonly WatchDesk $watches;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->gate = new ToolApprovalGate();
        $this->desk = new HumanQuestionDesk();
        $this->watches = new WatchDesk();
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
     * La réponse à une question posée par l'agent. C'est l'autre sens de la conversation : ici
     * l'humain ne pilote pas, il renseigne.
     *
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('question_answered')]
    public function onQuestionAnswered(array $payload): void
    {
        $callId = (string) ($payload['callId'] ?? '');
        if ('' !== $callId) {
            $this->desk->answer($callId, \is_array($payload['answers'] ?? null) ? $payload['answers'] : []);
        }
    }

    /**
     * L'alerte qui lève une veille. Elle vient du dehors — une supervision, un webhook, un autre
     * agent — et c'est le journal, pas le modèle, qui rappellera à l'agent ce qu'il comptait faire.
     *
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('alerte')]
    public function onAlerte(array $payload): void
    {
        $callId = (string) ($payload['callId'] ?? '');
        if ('' !== $callId) {
            $this->watches->raise($callId, (string) ($payload['observation'] ?? ''));
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
        string $model = 'mistral-small-latest',
        string $mode = 'standard',
        string $systemPrompt = 'Tu es un assistant concis. Utilise les outils quand ils répondent mieux que toi.',
        ?string $prompt = null,
        int $maxTurns = 20,
        int $maxToolCalls = 10,
        ?float $humanTimeoutSeconds = null,
        int $contextTokens = 24_000,
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
            desk: $this->desk,
            watches: $this->watches,
            mode: fn(): AgentMode => $this->mode,
            guard: $guard,
            humanTimeout: Duration::fromWireValue($humanTimeoutSeconds),
            budget: new ContextBudget($contextTokens),
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
