<?php

declare(strict_types=1);

namespace App\Ai\Workflow;

use App\Ai\Activity\ModelInvocationActivityInterface;
use App\Ai\Chat\TranscriptMessage;
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
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\MultiPartResult;

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
 * Deux bornes ferment le run, et aucune ne perd le fil :
 * - `idleTimeoutSeconds` — un silence assez long vaut une fin. Le run se termine, la page propose
 *   de le reprendre, et le fil repart dans la charge de la nouvelle exécution.
 * - `rolloverAfterTurns` — au bout de N tours, `continueAsNew` ouvre un run neuf **dans la même
 *   exécution** : même `workflowId`, même URL, journal vierge, fil transporté.
 *
 * Ce qui est transporté est le fil *parlé*, pas le journal : les appels d'outils et leurs retours
 * appartiennent au run qui s'achève. Et `compactHistory` le réduit à un résumé avant le premier
 * tour — ce qu'on veut d'une reprise froide, où rejouer la conversation mot pour mot ferait payer
 * au premier tour tout ce que le run précédent avait déjà coûté.
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

    /** La consigne par défaut. Une constante parce qu'un appelant a besoin de la citer. */
    public const SYSTEM_PROMPT = 'Tu es un assistant concis. Utilise les outils quand ils répondent mieux que toi.';

    /**
     * Ce qu'on demande au modèle quand une conversation froide redémarre.
     *
     * Le résumé remplace le fil : il doit donc porter ce dont le tour suivant a besoin — la demande,
     * ce qui a été fait, ce qui reste ouvert — et rien de la mécanique.
     */
    private const COMPACTION_PROMPT = 'Tu reprends une conversation interrompue. Résume-la en '
        . 'quelques phrases : ce que la personne a demandé, ce qui a été fait pour elle, et ce qui '
        . 'reste en suspens. Écris le résumé seul, sans préambule ni formule d\'introduction.';

    /** @var list<string> */
    private array $inbox = [];

    private bool $closed = false;

    private AgentMode $mode = AgentMode::Standard;

    /**
     * Ce que cet agent ne peut pas dépasser, quoi qu'il demande. `auto` pour un agent de premier
     * rang — c'est-à-dire aucune borne — et le mode effectif du parent pour un délégué.
     */
    private AgentMode $ceiling = AgentMode::Auto;

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
    /**
     * Le mode change, **sans jamais desserrer le plafond**.
     *
     * Le plafond vaut à l'entrée *et* en cours de route : un sous-agent qui accepterait
     * `set_mode: auto` n'aurait pas de plafond du tout, et déléguer redeviendrait le chemin
     * d'échappement de la garde. Un agent de premier rang a `auto` pour plafond — la borne ne lui
     * coûte rien.
     */
    #[AsSignalMethod('set_mode')]
    public function onSetMode(array $payload): void
    {
        $demande = AgentMode::tryFrom((string) ($payload['mode'] ?? ''));
        if (null === $demande || $demande->loosens($this->ceiling)) {
            return;
        }

        $this->mode = $demande;
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
     * Réduire une conversation froide à ce qu'il faut en savoir.
     *
     * Un appel modèle, pas une boucle d'agent : il n'y a rien à outiller ici, et passer par
     * `Runner` ne ferait qu'exposer la compaction aux gardes et aux appels d'outils. L'appel sort
     * du journal comme les autres — donc rejoué, donc payé une fois.
     *
     * Un résumé vide n'est pas un résumé : le fil repart alors tel quel. C'est le seul choix qui
     * garde l'affichage et le sac d'accord — la projection, elle aussi, retombe sur le fil brut
     * quand le journal ne porte pas de résumé exploitable.
     *
     * @param list<TranscriptMessage> $thread
     *
     * @return list<TranscriptMessage>
     */
    private function compact(string $model, array $thread): array
    {
        $result = $this->environment->await(
            $this->environment
                ->activityStub(ModelInvocationActivityInterface::class)
                ->compactConversation($model, ['messages' => [
                    ['role' => 'system', 'content' => self::COMPACTION_PROMPT],
                    // Sans étiquette : ce qui part au modèle est la conversation, pas la façon
                    // dont on la lui a présentée la fois d'avant.
                    ...TranscriptMessage::listToWire(
                        array_map(static fn (TranscriptMessage $m): TranscriptMessage => $m->stripped(), $thread),
                    ),
                ]], []),
        );

        // La forme d'une réponse « chat completions », la même que lit la projection.
        $digest = trim((string) ($result['choices'][0]['message']['content'] ?? ''));

        return '' === $digest ? $thread : [TranscriptMessage::compaction($digest)];
    }

    /**
     * La charge arrive du journal, donc en tableaux : `$tools` est converti en
     * {@see ToolDefinition} dès l'entrée, et plus rien en dessous ne manipule de tableau associatif.
     *
     * @param array<string, array{description?: string, parameters?: array<string, mixed>|null, effect?: string}> $tools
     * @param float|null                                                                                         $idleTimeoutSeconds silence au bout duquel l'exécution se termine ; `null` = jamais
     * @param int|null                                                                                           $rolloverAfterTurns tours au bout desquels le run passe la main à un run neuf ; `null` = jamais
     * @param bool                                                                                               $compactHistory     remplacer le fil repris par un résumé avant le premier tour
     * @param list<array{role?: string, content?: string|null}>                                                   $history            le fil repris d'une exécution précédente
     * @param list<string>                                                                                        $pending            messages reçus mais pas encore traités, transmis par le run précédent
     *
     * @return string la dernière réponse de l'agent
     */
    #[AsWorkflowMethod]
    public function run(
        array $tools = [],
        string $model = 'mistral-small-latest',
        string $mode = 'standard',
        string $modeCeiling = 'auto',
        string $systemPrompt = self::SYSTEM_PROMPT,
        ?string $prompt = null,
        int $maxTurns = 20,
        int $maxToolCalls = 10,
        ?float $humanTimeoutSeconds = null,
        int $contextTokens = 24_000,
        ?float $idleTimeoutSeconds = null,
        ?int $rolloverAfterTurns = null,
        bool $compactHistory = false,
        array $history = [],
        array $pending = [],
        ?ToolGuardInterface $guard = null,
    ): string {
        // Le plafond d'abord : le mode demandé s'y plie, il ne le contourne pas.
        $this->ceiling = AgentMode::tryFrom($modeCeiling) ?? AgentMode::Auto;
        $this->mode = AgentMode::strictest($this->ceiling, AgentMode::tryFrom($mode) ?? AgentMode::Standard);

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
            desk: $this->desk,
            watches: $this->watches,
            mode: fn(): AgentMode => $this->mode,
            guard: $guard,
            humanTimeout: Duration::fromWireValue($humanTimeoutSeconds),
            budget: new ContextBudget($contextTokens),
        );

        // Le sac est reconstruit à chaque rejeu ; `$thread` en est la trace transportable — mêmes
        // tours, forme du fil, sans les appels d'outils.
        $thread = TranscriptMessage::listFromWire($history);
        if ($compactHistory && [] !== $thread) {
            $thread = $this->compact($model, $thread);
        }

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
            // Le sac reçoit le résultat entier — `Message::toContent()` déplie un `MultiPartResult`,
            // et le bloc de raisonnement repart ainsi au tour suivant. Le fil, lui, ne veut que le
            // texte : `getContent()` d'un multi-parts rend un tableau, pas une chaîne.
            $messages->add(Message::ofAssistant($result));
            $answer = $result instanceof MultiPartResult ? $result->asText() : (string) $result->getContent();
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
                    'humanTimeoutSeconds' => $humanTimeoutSeconds,
                    'contextTokens' => $contextTokens,
                    'idleTimeoutSeconds' => $idleTimeoutSeconds,
                    'rolloverAfterTurns' => $rolloverAfterTurns,
                    // Le relais transmet le fil tel quel : il a lieu au milieu d'une conversation
                    // vivante, où perdre le détail se paierait tout de suite. La compaction est
                    // pour les reprises froides.
                    'compactHistory' => false,
                    'history' => TranscriptMessage::listToWire($thread),
                    'pending' => $this->inbox,
                ]);
            }
        }

        return $answer;
    }
}
