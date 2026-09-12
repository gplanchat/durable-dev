<?php

declare(strict_types=1);

namespace App\Ai\Durable;

use App\Ai\Activity\AgentToolActivityInterface;
use App\Ai\Guard\AgentMode;
use App\Ai\Guard\ApprovalOutcome;
use App\Ai\Guard\ToolApprovalGate;
use App\Ai\Guard\ToolGuardInterface;
use App\Ai\Question\AskUserQuestion;
use App\Ai\Question\HumanQuestionDesk;
use App\Ai\Question\PendingQuestion;
use App\Ai\Team\DelegateTool;
use App\Ai\Watch\UnknownWatchSubject;
use App\Ai\Watch\Watch;
use App\Ai\Watch\WatchDesk;
use App\Ai\Watch\WatchSubject;
use App\Ai\Watch\WatchTool;
use App\Ai\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Toolbox\ToolExecutorInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * `Runner` délègue ici par `yield from` ; le `Fiber::suspend()` de {@see WorkflowEnvironment::await()}
 * traverse la délégation de générateur sans rien casser.
 *
 * ponytail: exécution séquentielle. `$environment->all(...)` paralléliserait les appels d'un même
 * tour — à faire quand un tour a réellement plusieurs outils lents.
 */
final class DurableToolExecutor implements ToolExecutorInterface
{
    private readonly ActivityStub $stub;

    /**
     * @param \Closure(): AgentMode $mode         le mode est de l'état de workflow, il peut changer
     *                                            entre deux tours — donc il se lit au moment de la décision
     * @param Duration|null         $humanTimeout échéance de toute attente humaine — validation d'un
     *                                            outil comme réponse à une question — globale à
     *                                            l'instance d'agent ; `null` = attendre indéfiniment
     */
    public function __construct(
        private readonly WorkflowEnvironment $environment,
        private readonly ToolGuardInterface $guard,
        private readonly ToolApprovalGate $gate,
        private readonly HumanQuestionDesk $desk,
        private readonly WatchDesk $watches,
        private readonly \Closure $mode,
        private readonly ?Duration $humanTimeout = null,
        private readonly string $model = 'gpt-4o-mini',
        ?ActivityOptions $options = null,
    ) {
        $this->stub = $environment->activityStub(AgentToolActivityInterface::class, $options);
    }

    public function execute(array $toolCalls): \Generator
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $decision = $this->guard->decide($toolCall, ($this->mode)());

            if ($decision->isDenied()) {
                yield new Progress('tool_denied', (string) $decision->reason, $toolCall);
                $results[] = new ToolResult($toolCall, \sprintf('Refusé : %s', $decision->reason));

                continue;
            }

            if ($decision->needsApproval()) {
                $this->gate->ask($toolCall, (string) $decision->reason);
                yield new Progress('tool_approval', (string) $decision->reason, $toolCall);

                // Suspension, pas attente : le processus peut mourir ici, l'accord peut arriver
                // demain, le workflow reprendra à cette ligne. L'échéance est un minuteur du
                // journal (DUR032), donc elle survit au redémarrage elle aussi.
                try {
                    $this->environment->await(
                        fn(): bool => $this->gate->isSettled($toolCall->getId()),
                        $this->humanTimeout,
                    );
                } catch (DeadlineExceededException) {
                    // Pas de réponse vaut refus — mais l'issue reste distincte d'un refus humain :
                    // personne n'a rien décidé. Elle est inscrite dans la porte pour que le rejeu
                    // la relise au lieu de replanifier un minuteur déjà tiré.
                    $this->gate->timeout($toolCall->getId());
                    yield new Progress('tool_expired', \sprintf('Validation de « %s » expirée.', $toolCall->getName()), $toolCall);
                }

                $outcome = $this->gate->outcome($toolCall->getId()) ?? ApprovalOutcome::Refused;
                if (!$outcome->isApproved()) {
                    $results[] = new ToolResult($toolCall, $outcome->message());

                    continue;
                }
            }

            // Le seul outil dont l'exécution est une suspension : son résultat n'est pas calculé,
            // il est attendu.
            if (AskUserQuestion::TOOL === $toolCall->getName()) {
                $results[] = new ToolResult($toolCall, yield from $this->askHuman($toolCall));

                continue;
            }

            // La troisième : elle attend un autre agent. Le délégué est un workflow enfant — sa
            // propre exécution, son propre journal, son propre modèle — et le parent se suspend
            // sur sa réponse comme sur n'importe quel `await`.
            if (DelegateTool::TOOL === $toolCall->getName()) {
                $results[] = new ToolResult($toolCall, yield from $this->delegate($toolCall));

                continue;
            }

            // L'autre suspension : celle-ci n'attend pas un humain devant une carte, mais un
            // événement du dehors.
            if (WatchTool::TOOL === $toolCall->getName()) {
                $results[] = new ToolResult($toolCall, yield from $this->standBy($toolCall));

                continue;
            }

            yield new Progress('tool_call', \sprintf('Exécution de l\'outil "%s".', $toolCall->getName()), $toolCall);

            $results[] = new ToolResult(
                $toolCall,
                $this->environment->await($this->stub->callTool($toolCall->getId(), $toolCall->getName(), $toolCall->getArguments())),
            );
        }

        return $results;
    }

    /**
     * La délégation : un sous-agent fait le travail, le parent attend sa réponse.
     *
     * **Le plafond est le sujet de cette méthode.** Le délégué reçoit le mode effectif du parent
     * comme plafond, et son propre mode ne peut plus le desserrer — ni à l'entrée, ni par un
     * `set_mode` ensuite. Sans ça, un agent en `standard` confierait à un sous-agent en `auto` ce
     * que sa garde lui refuse : la garde ne serait pas contournée, elle serait décorative.
     *
     * Le délégué ne reçoit **que** sa mission : ni le fil du parent, ni ses outils de suspension.
     * Un sous-agent que personne ne regarde n'a rien à demander à un humain.
     *
     * @return \Generator<int, Progress, mixed, string>
     */
    private function delegate(ToolCall $toolCall): \Generator
    {
        $arguments = $toolCall->getArguments();
        $mission = trim((string) ($arguments['mission'] ?? ''));
        if ('' === $mission) {
            return 'Une délégation sans mission n\'a rien à déléguer. Dis ce que le sous-agent doit faire.';
        }

        $ceiling = ($this->mode)();
        $modele = trim((string) ($arguments['modele'] ?? '')) ?: $this->model;

        yield new Progress('delegated', \sprintf('Mission confiée à un sous-agent (%s).', $modele), $toolCall);

        // ⚠ Positionnel, et pas par noms. `ChildWorkflowStub::argumentsToInput()` apparie les
        // arguments **par position** (`$arguments[$i]`) : PHP passe les arguments nommés à `__call`
        // dans un tableau à clés de chaînes, aucun indice n'y répond, et *tous* les paramètres
        // retombent sur leur valeur par défaut. Sans exception, sans trace — le sous-agent démarre
        // avec un prompt vide et attend un message qui ne viendra jamais. C'est un défaut du cœur,
        // pas d'ici ; en attendant, l'ordre de la signature fait foi.
        $reponse = (string) $this->environment->await(
            $this->environment->childWorkflowStub(DurableAgentWorkflow::class)->run(
                [],                                   // tools
                $modele,                              // model
                $ceiling->value,                      // mode
                $ceiling->value,                      // modeCeiling
                DurableAgentWorkflow::SYSTEM_PROMPT,  // systemPrompt
                $mission,                             // prompt
                1,                                    // maxTurns
            ),
        );

        return \sprintf('Le sous-agent (%s) répond : %s', $modele, $reponse);
    }

    /**
     * La veille : l'agent dort jusqu'à l'alerte, et se réveille en retrouvant son intention.
     *
     * @return \Generator<int, Progress, mixed, string> ce que le modèle relira à la place d'un résultat d'outil
     */
    private function standBy(ToolCall $toolCall): \Generator
    {
        try {
            $watch = Watch::fromArguments($toolCall->getId(), $toolCall->getArguments());
        } catch (UnknownWatchSubject $refus) {
            // Rendu au modèle comme un résultat d'outil, pas levé : c'est une consigne mal suivie,
            // pas une panne, et l'agent peut se corriger au tour suivant. Une veille armée sur un
            // sujet inconnu, elle, dormirait jusqu'à son échéance sans que rien ne le dise.
            yield new Progress('watch_refused', $refus->subject, $toolCall);

            return \sprintf(
                '%s Sujets connus : %s. Reprends avec l\'un d\'eux, ou fais autrement.',
                $refus->getMessage(),
                implode(', ', WatchSubject::values()),
            );
        }

        $this->watches->watch($watch);
        yield new Progress('watch_started', $watch->observation, $toolCall);

        // L'échéance de la veille est celle que le modèle a demandée ; à défaut, le budget humain
        // de l'agent — une veille sans borne aucune finirait par ne plus être une veille.
        $deadline = Duration::fromWireValue($toolCall->getArguments()['deadlineSeconds'] ?? null) ?? $this->humanTimeout;

        try {
            $this->environment->await(fn(): bool => $this->watches->isSettled($toolCall->getId()), $deadline);
        } catch (DeadlineExceededException) {
            $this->watches->raise($toolCall->getId(), '');
            yield new Progress('watch_expired', $watch->observation, $toolCall);

            return \sprintf(
                'La veille « %s » a expiré sans alerte. Tu comptais : %s. Reprends la main et dis ce que tu fais.',
                $watch->observation,
                $watch->intention,
            );
        }

        // Le réveil rend l'observation **et** l'intention : c'est ce qui dispense l'agent de se
        // souvenir de ce qu'il faisait il y a trois jours.
        return \sprintf(
            'Alerte sur « %s » : %s. Tu comptais : %s.',
            $watch->observation,
            '' === $this->watches->observationOf($toolCall->getId()) ? 'rien de plus n’a été rapporté' : $this->watches->observationOf($toolCall->getId()),
            $watch->intention,
        );
    }

    /**
     * @return \Generator<int, Progress, mixed, string> ce que le modèle relira à la place d'un résultat d'outil
     */
    private function askHuman(ToolCall $toolCall): \Generator
    {
        $question = PendingQuestion::fromArguments($toolCall->getId(), $toolCall->getArguments());
        $this->desk->ask($question);
        yield new Progress('question_asked', $question->question, $toolCall);

        try {
            $this->environment->await(
                fn(): bool => $this->desk->isAnswered($toolCall->getId()),
                $this->humanTimeout,
            );
        } catch (DeadlineExceededException) {
            // Comme pour une validation : l'absence de réponse est inscrite, sinon le rejeu
            // replanifierait un minuteur déjà tiré.
            $this->desk->answer($toolCall->getId(), []);
            yield new Progress('question_expired', \sprintf('Question « %s » restée sans réponse.', $question->header), $toolCall);
        }

        $answers = $this->desk->answersOf($toolCall->getId());

        return [] === $answers
            ? 'Aucune réponse : l’utilisateur n’a rien choisi avant l’échéance. Poursuis sans cette information, ou dis ce qui te manque.'
            : implode(' ; ', $answers);
    }
}
