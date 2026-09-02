<?php

declare(strict_types=1);

namespace App\Ai\Workflow;

use App\Ai\Chat\TranscriptMessage;
use App\Ai\Durable\DurableAgentFactory;
use App\Ai\Guard\AgentMode;
use App\Ai\Guard\ToolApprovalGate;
use App\Ai\Guard\ToolGuardInterface;
use App\Ai\Tool\ToolDefinition;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\DeadlineExceededException;
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
 * Deux bornes ferment le run, et aucune ne perd le fil :
 * - `idleTimeoutSeconds` — un silence assez long vaut une fin. Le run se termine, la page propose
 *   de le reprendre, et le fil repart dans la charge de la nouvelle exécution.
 * - `rolloverAfterTurns` — au bout de N tours, `continueAsNew` ouvre un run neuf **dans la même
 *   exécution** : même `workflowId`, même URL, journal vierge, fil transporté.
 *
 * Ce qui est transporté est le fil *parlé*, pas le journal : les appels d'outils et leurs retours
 * appartiennent au run qui s'achève.
 *
 * Le relais n'a pas la même surface selon le backend, et c'est ce qui le rend opt-in : sur Temporal
 * le `workflowId` ne bouge pas, donc l'URL du chat non plus ; sur les autres,
 * {@see \Gplanchat\Durable\Handler\ResumeWorkflowHandler} ouvre le run suivant sous un
 * `executionId` neuf, qu'il faudrait suivre. La clôture sur inactivité, elle, se comporte pareil
 * partout — c'est le chemin par défaut.
 */
#[AsWorkflow(self::TYPE)]
final class DurableAgentWorkflow
{
    /**
     * Le journal et Temporal désignent un workflow par son alias, jamais par son FQCN
     * ({@see \Gplanchat\Durable\WorkflowRegistry}) : `continueAsNew` doit donner celui-là.
     */
    public const TYPE = 'Ai_DurableAgent';

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
     * @param float|null                                                                                         $idleTimeoutSeconds silence au bout duquel l'exécution se termine ; `null` = jamais
     * @param int|null                                                                                           $rolloverAfterTurns tours au bout desquels le run passe la main à un run neuf ; `null` = jamais
     * @param list<array{role?: string, content?: string|null}>                                                   $history            le fil repris d'une exécution précédente
     * @param list<string>                                                                                        $pending            messages reçus mais pas encore traités, transmis par le run précédent
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
        ?float $idleTimeoutSeconds = null,
        ?int $rolloverAfterTurns = null,
        array $history = [],
        array $pending = [],
        ?ToolGuardInterface $guard = null,
    ): string {
        $this->mode = AgentMode::tryFrom($mode) ?? AgentMode::Standard;

        // Ce que le run précédent n'a pas eu le temps de traiter passe devant : ces messages sont
        // arrivés avant ceux que le nouveau run recevra.
        foreach ($pending as $carried) {
            $this->inbox[] = (string) $carried;
        }

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

        // Le sac est reconstruit à chaque rejeu ; `$thread` en est la trace transportable — mêmes
        // tours, forme du fil, sans les appels d'outils.
        $thread = TranscriptMessage::listFromWire($history);
        $messages = new MessageBag(Message::forSystem($systemPrompt));
        foreach ($thread as $carried) {
            $messages->add($carried->isUser()
                ? Message::ofUser((string) $carried->content)
                : Message::ofAssistant((string) $carried->content));
        }

        $answer = '';
        $turns = 0;

        while (!$this->closed && $turns < $maxTurns) {
            try {
                $this->environment->await(
                    fn(): bool => [] !== $this->inbox || $this->closed,
                    $idleTimeoutSeconds,
                );
            } catch (DeadlineExceededException) {
                // Un silence assez long vaut une fin. Rien n'est perdu : le fil est au journal, et
                // la page propose de le reprendre dans une exécution neuve.
                break;
            }

            if ($this->closed) {
                break;
            }

            $text = (string) array_shift($this->inbox);
            $messages->add(Message::ofUser($text));
            $thread[] = TranscriptMessage::user($text);

            // `Runner` ajoute lui-même les messages de la boucle d'outils au sac, mais pas la
            // réponse finale : elle sort de la boucle sans y passer.
            $result = $agent->call($messages)->getResult();
            $messages->add(Message::ofAssistant($result));
            $answer = (string) $result->getContent();
            $thread[] = TranscriptMessage::assistant($answer);

            ++$turns;

            // Le relais se prend ici, entre deux tours : rien n'est en vol, aucune garde n'attend
            // de décision, et ce que la file a reçu pendant le tour part avec.
            //
            // ponytail: le seuil est un nombre de tours, pas la vraie grandeur. Ce qui coûte, c'est
            // que chaque tour renvoie tout le sac au modèle — la taille de la charge du dernier
            // `ai_model_invoke` est le déclencheur juste, le compte de tours n'en est que le proxy.
            if (null !== $rolloverAfterTurns && $turns >= $rolloverAfterTurns && !$this->closed) {
                // ponytail: un signal qui arrive pendant la tâche qui émet la commande peut se
                // perdre — fenêtre irréductible, et la raison pour laquelle le relais est opt-in
                // là où la clôture sur inactivité est le chemin par défaut.
                $this->environment->continueAsNew(self::TYPE, [
                    'tools' => $tools,
                    'model' => $model,
                    'mode' => $this->mode->value,
                    'systemPrompt' => $systemPrompt,
                    'maxTurns' => $maxTurns,
                    'maxToolCalls' => $maxToolCalls,
                    'approvalTimeoutSeconds' => $approvalTimeoutSeconds,
                    'idleTimeoutSeconds' => $idleTimeoutSeconds,
                    'rolloverAfterTurns' => $rolloverAfterTurns,
                    'history' => TranscriptMessage::listToWire($thread),
                    'pending' => $this->inbox,
                ]);
            }
        }

        return $answer;
    }
}
